"""Prometheus /metrics endpoint: gating, exposition format, counters, and token."""

from __future__ import annotations

import re

import pytest
from fastapi.testclient import TestClient

from app.core import metrics as metrics_mod
from app.core.config import settings
from app.main import app as singleton_app
from app.main import create_app


@pytest.fixture()
def metrics_client(monkeypatch):
    monkeypatch.setattr(settings, "METRICS_ENABLED", True)
    monkeypatch.setattr(settings, "METRICS_PATH", "/metrics")
    monkeypatch.setattr(settings, "METRICS_TOKEN", "")
    metrics_mod.REGISTRY.reset()
    with TestClient(create_app()) as client:
        yield client


def test_metrics_disabled_by_default():
    # The shared singleton app is built with METRICS_ENABLED=false.
    assert settings.METRICS_ENABLED is False
    resp = TestClient(singleton_app).get("/metrics")
    assert resp.status_code == 404


def test_exposition_format_and_content_type(metrics_client):
    metrics_client.get("/health")
    resp = metrics_client.get("/metrics")
    assert resp.status_code == 200
    assert "text/plain; version=0.0.4" in resp.headers["content-type"]
    body = resp.text
    assert "# TYPE sentinel_build_info gauge" in body
    assert 'sentinel_build_info{version="' in body
    assert "# TYPE sentinel_http_requests_total counter" in body
    assert "# TYPE sentinel_http_request_duration_seconds histogram" in body
    assert 'sentinel_http_request_duration_seconds_bucket{method="GET",le="+Inf"}' in body
    assert 'sentinel_http_request_duration_seconds_count{method="GET"}' in body


def test_request_counter_increments(metrics_client):
    for _ in range(3):
        assert metrics_client.get("/health").status_code == 200
    body = metrics_client.get("/metrics").text
    match = re.search(
        r'sentinel_http_requests_total\{method="GET",status="200"\} (\d+)', body
    )
    assert match is not None, body
    assert int(match.group(1)) >= 3


def test_scrape_endpoint_is_not_self_counted(metrics_client):
    # Scraping twice must not create a counter series for the metrics path.
    metrics_client.get("/metrics")
    body = metrics_client.get("/metrics").text
    # Only /health-style traffic is counted; no series should reference /metrics.
    assert "/metrics" not in body


def test_token_protection(monkeypatch):
    monkeypatch.setattr(settings, "METRICS_ENABLED", True)
    monkeypatch.setattr(settings, "METRICS_PATH", "/metrics")
    monkeypatch.setattr(settings, "METRICS_TOKEN", "s3cr3t-scrape-token")
    metrics_mod.REGISTRY.reset()
    with TestClient(create_app()) as client:
        assert client.get("/metrics").status_code == 401
        assert (
            client.get(
                "/metrics", headers={"Authorization": "Bearer wrong"}
            ).status_code
            == 401
        )
        ok = client.get(
            "/metrics", headers={"Authorization": "Bearer s3cr3t-scrape-token"}
        )
        assert ok.status_code == 200
        assert "sentinel_build_info" in ok.text
