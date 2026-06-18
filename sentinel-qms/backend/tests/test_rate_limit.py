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


def test_distinct_credentials_have_independent_budgets():
    client = _app(limit=1)
    h1 = {"Authorization": "Bearer token-aaa"}
    h2 = {"Authorization": "Bearer token-bbb"}
    assert client.get(f"{settings.API_V1_PREFIX}/ping", headers=h1).status_code == 200
    # Same IP but a different credential gets its own bucket.
    assert client.get(f"{settings.API_V1_PREFIX}/ping", headers=h2).status_code == 200
    # Re-using the first credential is now over its budget.
    assert client.get(f"{settings.API_V1_PREFIX}/ping", headers=h1).status_code == 429
