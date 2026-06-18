"""Global rate-limit middleware: budgeting, headers, isolation, and exemptions."""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.core.config import settings
from app.core.middleware import RateLimitMiddleware


def _app(limit: int = 3, window: int = 60) -> TestClient:
    app = FastAPI()
    app.add_middleware(RateLimitMiddleware, limit=limit, window_seconds=window)

    @app.get(f"{settings.API_V1_PREFIX}/ping")
    def ping() -> dict:
        return {"ok": True}

    @app.get("/health")
    def health() -> dict:
        return {"status": "ok"}

    return TestClient(app)


def test_allows_within_limit_and_sets_headers():
    client = _app(limit=3)
    r = client.get(f"{settings.API_V1_PREFIX}/ping")
    assert r.status_code == 200
    assert r.headers["X-RateLimit-Limit"] == "3"
    # First request consumes one of three.
    assert r.headers["X-RateLimit-Remaining"] == "2"
    assert int(r.headers["X-RateLimit-Reset"]) >= 1


def test_blocks_over_limit_with_429_and_retry_after():
    client = _app(limit=2)
    assert client.get(f"{settings.API_V1_PREFIX}/ping").status_code == 200
    assert client.get(f"{settings.API_V1_PREFIX}/ping").status_code == 200
    blocked = client.get(f"{settings.API_V1_PREFIX}/ping")
    assert blocked.status_code == 429
    assert blocked.json()["error"]["code"] == "rate_limited"
    assert int(blocked.headers["Retry-After"]) >= 1
    assert blocked.headers["X-RateLimit-Remaining"] == "0"


def test_health_is_exempt():
    client = _app(limit=1)
    # Many health probes never trip the limiter (non-API path).
    for _ in range(5):
        assert client.get("/health").status_code == 200


def test_credential_rotation_cannot_bypass_limit():
    """Varying the Authorization header must NOT mint fresh budgets (the key is
    the source IP, not a client-supplied header)."""
    client = _app(limit=1)
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping",
            headers={"Authorization": "Bearer token-aaa"},
        ).status_code
        == 200
    )
    # Same socket IP, different credential → still the same bucket → blocked.
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping",
            headers={"Authorization": "Bearer token-bbb"},
        ).status_code
        == 429
    )


def test_xff_ignored_unless_proxy_trusted(monkeypatch):
    """X-Forwarded-For must not create new buckets when proxy trust is off."""
    monkeypatch.setattr(settings, "TRUST_PROXY_HEADERS", False)
    client = _app(limit=1)
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping", headers={"X-Forwarded-For": "1.1.1.1"}
        ).status_code
        == 200
    )
    # Spoofing a different forwarded IP does not escape the limit.
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping", headers={"X-Forwarded-For": "2.2.2.2"}
        ).status_code
        == 429
    )


def test_xff_used_when_proxy_trusted(monkeypatch):
    """Behind a trusted proxy, each real client IP gets its own budget."""
    monkeypatch.setattr(settings, "TRUST_PROXY_HEADERS", True)
    client = _app(limit=1)
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping", headers={"X-Forwarded-For": "1.1.1.1"}
        ).status_code
        == 200
    )
    # A different real client IP gets an independent bucket.
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping", headers={"X-Forwarded-For": "2.2.2.2"}
        ).status_code
        == 200
    )
    # Re-using the first client IP is now over budget.
    assert (
        client.get(
            f"{settings.API_V1_PREFIX}/ping", headers={"X-Forwarded-For": "1.1.1.1"}
        ).status_code
        == 429
    )
