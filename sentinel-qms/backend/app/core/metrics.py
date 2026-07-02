"""Dependency-free Prometheus metrics for the Sentinel QMS API.

Exposes a Prometheus/OpenMetrics 0.0.4 text exposition (served at
``settings.METRICS_PATH`` when ``settings.METRICS_ENABLED`` is true). This is
implemented without a third-party client library so it adds no runtime
dependency, and it deliberately keeps label cardinality bounded — series are
labeled only by HTTP method and status code, never by the raw request path
(which is unbounded and would blow up the time-series database).

Collected series:

* ``sentinel_build_info{version}``      — build metadata gauge (always ``1``)
* ``sentinel_http_requests_total{method,status}`` — request counter
* ``sentinel_http_requests_in_progress`` — in-flight request gauge
* ``sentinel_http_request_duration_seconds{method}`` — latency histogram
"""

from __future__ import annotations

import threading
import time
from collections import defaultdict

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request

from app import __version__

# Prometheus text exposition content type (format version 0.0.4).
CONTENT_TYPE = "text/plain; version=0.0.4; charset=utf-8"

# Upper bounds (seconds) for the latency histogram; the implicit ``+Inf`` bucket
# is appended by the renderer.
_BUCKETS: tuple[float, ...] = (
    0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0,
)


def _esc(value: str) -> str:
    """Escape a Prometheus label value (backslash, double-quote, newline)."""
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def _fmt(value: float) -> str:
    """Render a float without scientific notation, trimming trailing zeros."""
    return f"{value:.6f}".rstrip("0").rstrip(".") or "0"


class MetricsRegistry:
    """Thread-safe in-process metric store.

    A single module-level :data:`REGISTRY` instance is shared by the middleware
    and the scrape endpoint. All state is process-local (like the rate limiter);
    in a scaled deployment each replica exposes its own series and the scraper
    aggregates them.
    """

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._requests: dict[tuple[str, str], int] = defaultdict(int)
        self._in_progress = 0
        # Per-method cumulative histogram buckets (+ the trailing +Inf slot),
        # running sum, and observation count.
        self._hist_buckets: dict[str, list[int]] = defaultdict(
            lambda: [0] * (len(_BUCKETS) + 1)
        )
        self._hist_sum: dict[str, float] = defaultdict(float)
        self._hist_count: dict[str, int] = defaultdict(int)

    def inc_in_progress(self) -> None:
        with self._lock:
            self._in_progress += 1

    def dec_in_progress(self) -> None:
        with self._lock:
            self._in_progress -= 1

    def observe(self, method: str, status: int, duration_s: float) -> None:
        """Record one completed request."""
        with self._lock:
            self._requests[(method, str(status))] += 1
            buckets = self._hist_buckets[method]
            for i, edge in enumerate(_BUCKETS):
                if duration_s <= edge:
                    buckets[i] += 1
            buckets[-1] += 1  # +Inf bucket counts every observation
            self._hist_sum[method] += duration_s
            self._hist_count[method] += 1

    def reset(self) -> None:
        """Clear all series (used by tests)."""
        with self._lock:
            self._requests.clear()
            self._in_progress = 0
            self._hist_buckets.clear()
            self._hist_sum.clear()
            self._hist_count.clear()

    def render(self) -> str:
        """Return the full Prometheus text exposition for the current state."""
        with self._lock:
            requests = dict(self._requests)
            in_progress = self._in_progress
            hist_buckets = {k: list(v) for k, v in self._hist_buckets.items()}
            hist_sum = dict(self._hist_sum)
            hist_count = dict(self._hist_count)

        lines: list[str] = []

        lines.append("# HELP sentinel_build_info Build metadata (constant 1).")
        lines.append("# TYPE sentinel_build_info gauge")
        lines.append(f'sentinel_build_info{{version="{_esc(__version__)}"}} 1')

        lines.append("# HELP sentinel_http_requests_total Total HTTP requests processed.")
        lines.append("# TYPE sentinel_http_requests_total counter")
        for (method, status), count in sorted(requests.items()):
            lines.append(
                f'sentinel_http_requests_total{{method="{_esc(method)}",'
                f'status="{_esc(status)}"}} {count}'
            )

        lines.append("# HELP sentinel_http_requests_in_progress In-flight HTTP requests.")
        lines.append("# TYPE sentinel_http_requests_in_progress gauge")
        lines.append(f"sentinel_http_requests_in_progress {in_progress}")

        lines.append(
            "# HELP sentinel_http_request_duration_seconds HTTP request latency (seconds)."
        )
        lines.append("# TYPE sentinel_http_request_duration_seconds histogram")
        for method in sorted(hist_count):
            buckets = hist_buckets[method]
            m = _esc(method)
            for i, edge in enumerate(_BUCKETS):
                lines.append(
                    f'sentinel_http_request_duration_seconds_bucket{{method="{m}",'
                    f'le="{_fmt(edge)}"}} {buckets[i]}'
                )
            lines.append(
                f'sentinel_http_request_duration_seconds_bucket{{method="{m}",'
                f'le="+Inf"}} {buckets[-1]}'
            )
            lines.append(
                f'sentinel_http_request_duration_seconds_sum{{method="{m}"}} '
                f"{_fmt(hist_sum[method])}"
            )
            lines.append(
                f'sentinel_http_request_duration_seconds_count{{method="{m}"}} '
                f"{hist_count[method]}"
            )

        return "\n".join(lines) + "\n"


# Module-level singleton shared by the middleware and the scrape endpoint.
REGISTRY = MetricsRegistry()


class MetricsMiddleware(BaseHTTPMiddleware):
    """Time every request and record it into :data:`REGISTRY`.

    The scrape endpoint itself is excluded so a monitoring poll never inflates
    the very counters it reports.
    """

    def __init__(self, app, *, metrics_path: str) -> None:  # noqa: ANN001
        super().__init__(app)
        self._metrics_path = metrics_path

    async def dispatch(self, request: Request, call_next):  # noqa: ANN001
        if request.url.path == self._metrics_path:
            return await call_next(request)
        REGISTRY.inc_in_progress()
        start = time.perf_counter()
        status = 500
        try:
            response = await call_next(request)
            status = response.status_code
            return response
        finally:
            REGISTRY.dec_in_progress()
            REGISTRY.observe(request.method, status, time.perf_counter() - start)
