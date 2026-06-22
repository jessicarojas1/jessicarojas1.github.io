"""Request-id, security-headers, rate-limiting, and request-logging middleware."""

from __future__ import annotations

import logging
import threading
import time
import uuid

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

from app.core.config import settings

logger = logging.getLogger("app.request")

# A CSP permissive enough for Swagger UI / ReDoc, restrictive elsewhere.
_DOCS_PATHS = ("/docs", "/redoc", "/openapi.json")
_DOCS_CSP = (
    "default-src 'self'; img-src 'self' data: https://fastapi.tiangolo.com; "
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    "worker-src 'self' blob:; connect-src 'self'"
)
_API_CSP = "default-src 'none'; frame-ancestors 'none'"
# CSP for the served React SPA (single-service mode). API is same-origin.
_APP_CSP = (
    "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    "img-src 'self' data:; font-src 'self' data:; connect-src 'self'; "
    "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
)


class RequestContextMiddleware(BaseHTTPMiddleware):
    """Assign a request id, time the request, and emit a structured log line."""

    async def dispatch(self, request: Request, call_next):  # noqa: ANN001
        request_id = request.headers.get("X-Request-ID") or uuid.uuid4().hex
        request.state.request_id = request_id
        start = time.perf_counter()
        try:
            response = await call_next(request)
        except Exception:
            elapsed = (time.perf_counter() - start) * 1000
            logger.exception(
                "request_failed",
                extra={
                    "request_id": request_id,
                    "method": request.method,
                    "path": request.url.path,
                    "duration_ms": round(elapsed, 2),
                },
            )
            raise
        elapsed = (time.perf_counter() - start) * 1000
        response.headers["X-Request-ID"] = request_id
        logger.info(
            "request",
            extra={
                "request_id": request_id,
                "method": request.method,
                "path": request.url.path,
                "status_code": response.status_code,
                "duration_ms": round(elapsed, 2),
            },
        )
        return response


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):  # noqa: ANN001
        response: Response = await call_next(request)
        response.headers.setdefault("X-Content-Type-Options", "nosniff")
        response.headers.setdefault("X-Frame-Options", "DENY")
        response.headers.setdefault("Referrer-Policy", "no-referrer")
        response.headers.setdefault(
            "Permissions-Policy", "geolocation=(), microphone=(), camera=()"
        )
        if settings.is_production:
            response.headers.setdefault(
                "Strict-Transport-Security",
                "max-age=63072000; includeSubDomains; preload",
            )
        path = request.url.path
        if any(path.startswith(p) for p in _DOCS_PATHS):
            csp = _DOCS_CSP
        elif settings.SERVE_FRONTEND and not path.startswith(settings.API_V1_PREFIX):
            # Non-API request in single-service mode → it's the SPA / its assets.
            csp = _APP_CSP
        else:
            csp = _API_CSP
        response.headers.setdefault("Content-Security-Policy", csp)
        return response


def _client_ip_from_xff(xff: str) -> str | None:
    """Pick the trustworthy client IP from an ``X-Forwarded-For`` value.

    XFF is ``client, proxy1, proxy2, ...`` where each hop *appends* the address
    it received from. The leftmost entry is the client-claimed origin and is
    freely spoofable, so it must NOT be used. The trustworthy hop is the one just
    before our own trusted proxies — i.e. the ``TRUSTED_PROXY_COUNT``-th entry
    from the RIGHT. Returns ``None`` when the header has no usable entry.
    """
    hops = [h.strip() for h in xff.split(",") if h.strip()]
    if not hops:
        return None
    # Index of the rightmost-untrusted hop: drop our trusted proxies from the
    # right, but never index past the start (clamp so a short list still yields
    # the leftmost remaining, real hop the edge proxy observed).
    idx = len(hops) - 1 - max(0, settings.TRUSTED_PROXY_COUNT - 1)
    return hops[max(0, idx)]


def _client_key(request: Request) -> str:
    """Stable per-caller bucket key, keyed strictly on source IP.

    The key must be something the caller cannot freely rotate, or the limit is
    trivially bypassed: keying on the (client-supplied) ``Authorization`` header
    would let an attacker mint a fresh budget per request simply by varying it.
    So we key on the transport peer address. ``X-Forwarded-For`` is honored ONLY
    when ``TRUST_PROXY_HEADERS`` is set — otherwise a direct attacker could spoof
    it to the same effect. Even when trusted, we take the rightmost-untrusted hop
    (per ``TRUSTED_PROXY_COUNT``), NOT the client-spoofable leftmost entry.
    """
    if settings.TRUST_PROXY_HEADERS:
        client_ip = _client_ip_from_xff(request.headers.get("X-Forwarded-For", ""))
        if client_ip:
            return "ip:" + client_ip
    client = request.client
    return "ip:" + (client.host if client else "unknown")


class RateLimitMiddleware(BaseHTTPMiddleware):
    """In-process fixed-window rate limiter for the JSON API.

    Keeps a per-caller counter that resets every ``RATE_LIMIT_WINDOW_SECONDS``.
    Only API paths are limited; ``/health`` and the served SPA/assets are exempt.
    Emits ``X-RateLimit-*`` headers on every limited response and ``Retry-After``
    on a 429. This is a single-process guard (defense-in-depth); a horizontally
    scaled deployment should also rate-limit at the gateway/WAF.
    """

    def __init__(self, app, *, limit: int, window_seconds: int,
                 redis_url: str | None = None) -> None:  # noqa: ANN001
        super().__init__(app)
        self._limit = max(1, limit)
        self._window = max(1, window_seconds)
        self._buckets: dict[str, tuple[float, int]] = {}
        self._lock = threading.Lock()
        self._redis_url = redis_url or None
        self._redis = None  # lazily created async client
        self._redis_ready = False

    def _get_redis(self):
        """Lazily build the async Redis client on first use. Returns None when no
        REDIS_URL is configured or the client library is unavailable, in which
        case the limiter transparently uses the in-process bucket map."""
        if self._redis_ready:
            return self._redis
        self._redis_ready = True
        if self._redis_url:
            try:
                import redis.asyncio as aioredis  # noqa: PLC0415

                self._redis = aioredis.from_url(self._redis_url, decode_responses=True)
            except Exception as exc:  # pragma: no cover - import/connection issues
                logger.warning(
                    "Rate limiter: Redis unavailable (%s); using in-process limiter.", exc
                )
                self._redis = None
        return self._redis

    def _check_memory(self, key: str) -> tuple[bool, int, int]:
        """Per-process fixed window. Returns ``(allowed, remaining, reset)``."""
        now = time.monotonic()
        with self._lock:
            window_start, count = self._buckets.get(key, (now, 0))
            if now - window_start >= self._window:
                window_start, count = now, 0
            count += 1
            self._buckets[key] = (window_start, count)
            # Opportunistic prune so the map can't grow without bound.
            if len(self._buckets) > 10_000:
                cutoff = now - self._window
                self._buckets = {k: v for k, v in self._buckets.items() if v[0] >= cutoff}
        reset = int(self._window - (now - window_start)) + 1
        remaining = max(0, self._limit - count)
        return count <= self._limit, remaining, reset

    async def _check_redis(self, client, key: str):  # noqa: ANN001
        """Shared fixed-window counter via atomic INCR+EXPIRE. Returns the usual
        tuple, or None to signal the caller should fall back to in-process."""
        window_epoch = int(time.time() // self._window)
        rkey = f"ratelimit:{key}:{window_epoch}"
        try:
            pipe = client.pipeline()
            pipe.incr(rkey, 1)
            pipe.expire(rkey, self._window)
            results = await pipe.execute()
            count = int(results[0])
        except Exception as exc:  # pragma: no cover - runtime redis failure
            logger.warning("Rate limiter: Redis error (%s); using in-process fallback.", exc)
            return None
        reset = int(self._window - (time.time() % self._window)) + 1
        remaining = max(0, self._limit - count)
        return count <= self._limit, remaining, reset

    async def _check(self, key: str) -> tuple[bool, int, int]:
        """Return ``(allowed, remaining, reset_seconds)`` and record the hit.
        Uses the shared Redis window when configured/available, else in-process."""
        client = self._get_redis()
        if client is not None:
            result = await self._check_redis(client, key)
            if result is not None:
                return result
        return self._check_memory(key)

    async def dispatch(self, request: Request, call_next):  # noqa: ANN001
        path = request.url.path
        # Limit only the JSON API; never the health probe or the served SPA.
        if not path.startswith(settings.API_V1_PREFIX):
            return await call_next(request)

        allowed, remaining, reset = await self._check(_client_key(request))
        if not allowed:
            request_id = getattr(request.state, "request_id", None)
            body = {
                "error": {
                    "code": "rate_limited",
                    "message": "Rate limit exceeded. Please slow down and retry shortly.",
                    "request_id": request_id,
                }
            }
            resp: Response = JSONResponse(status_code=429, content=body)
            resp.headers["Retry-After"] = str(reset)
        else:
            resp = await call_next(request)

        resp.headers["X-RateLimit-Limit"] = str(self._limit)
        resp.headers["X-RateLimit-Remaining"] = str(remaining)
        resp.headers["X-RateLimit-Reset"] = str(reset)
        return resp
