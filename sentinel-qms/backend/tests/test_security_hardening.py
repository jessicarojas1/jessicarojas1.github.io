"""SSRF egress guard + login brute-force throttling."""

from __future__ import annotations

import pytest

from app.core.config import settings
from app.core.net_guard import is_public_http_url


@pytest.mark.parametrize(
    "url",
    [
        "http://127.0.0.1/logo.png",
        "http://localhost/logo.png",
        "http://169.254.169.254/latest/meta-data/",  # cloud metadata
        "http://10.0.0.5/x",
        "http://192.168.1.1/x",
        "http://172.16.0.9/x",
        "http://[::1]/x",
        "ftp://example.com/x",
        "file:///etc/passwd",
        "not a url",
        "",
    ],
)
def test_blocks_non_public_urls(url):
    assert is_public_http_url(url) is False


@pytest.mark.parametrize("url", ["http://8.8.8.8/logo.png", "https://1.1.1.1/x"])
def test_allows_public_literal_ips(url):
    # Literal public IPs resolve to themselves with no DNS, so this is offline-safe.
    assert is_public_http_url(url) is True


def test_login_throttles_after_repeated_failures(client, seeded, monkeypatch):
    monkeypatch.setattr(settings, "LOGIN_MAX_FAILURES", 3)
    monkeypatch.setattr(settings, "LOGIN_FAILURE_WINDOW_MINUTES", 15)
    creds = {"username": "nobody@test.local", "password": "wrong-password"}

    for _ in range(3):
        r = client.post("/api/v1/auth/login", json=creds)
        assert r.status_code == 401, r.text

    blocked = client.post("/api/v1/auth/login", json=creds)
    assert blocked.status_code == 429, blocked.text


def test_valid_login_still_works_under_threshold(client, seeded):
    # A couple of failures must not lock out a subsequent correct login.
    for _ in range(2):
        client.post(
            "/api/v1/auth/login",
            json={"username": "admin@test.local", "password": "nope"},
        )
    ok = client.post(
        "/api/v1/auth/login",
        json={"username": "admin@test.local", "password": "AdminPass123!"},
    )
    assert ok.status_code == 200, ok.text
    assert "access_token" in ok.json()
