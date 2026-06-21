"""Refresh-token rotation, reuse detection, and revocation on logout.

The refresh token is delivered/accepted via an HttpOnly cookie (not the JSON
body), so the tests drive the flow through the TestClient's cookie jar.
"""

from __future__ import annotations

from app.core.cookies import REFRESH_COOKIE_NAME


def _login(client, email="qe@test.local", password="EngPass123!"):
    r = client.post("/api/v1/auth/login", json={"username": email, "password": password})
    assert r.status_code == 200, r.text
    # The refresh token is set as a cookie, not returned in the body.
    assert "refresh_token" not in r.json()
    assert REFRESH_COOKIE_NAME in r.cookies
    return r.json()


def _refresh_cookie(client) -> str:
    return client.cookies.get(REFRESH_COOKIE_NAME)


def test_refresh_rotates_token(client, seeded):
    _login(client)
    old_refresh = _refresh_cookie(client)

    r = client.post("/api/v1/auth/refresh")
    assert r.status_code == 200, r.text
    new_refresh = _refresh_cookie(client)
    # Rotation issues a brand-new refresh token (in the cookie).
    assert new_refresh != old_refresh
    # The new one works...
    assert client.post("/api/v1/auth/refresh").status_code == 200


def test_old_token_rejected_after_rotation(client, seeded):
    _login(client)
    old_refresh = _refresh_cookie(client)
    assert client.post("/api/v1/auth/refresh").status_code == 200
    # Reusing the rotated-away token is rejected. Send it explicitly via cookie
    # (the jar now holds the rotated successor).
    reuse = client.post(
        "/api/v1/auth/refresh", cookies={REFRESH_COOKIE_NAME: old_refresh}
    )
    assert reuse.status_code == 401


def test_reuse_detection_revokes_whole_chain(client, seeded):
    """Replaying a rotated token burns its successor too (theft response)."""
    _login(client)
    old_refresh = _refresh_cookie(client)
    assert client.post("/api/v1/auth/refresh").status_code == 200
    successor = _refresh_cookie(client)

    # Attacker replays the original (already rotated) token.
    assert (
        client.post(
            "/api/v1/auth/refresh", cookies={REFRESH_COOKIE_NAME: old_refresh}
        ).status_code
        == 401
    )

    # The legitimate successor must now be invalid as well.
    assert (
        client.post(
            "/api/v1/auth/refresh", cookies={REFRESH_COOKIE_NAME: successor}
        ).status_code
        == 401
    )


def test_logout_revokes_refresh_tokens(client, seeded):
    tokens = _login(client)
    refresh = _refresh_cookie(client)
    headers = {"Authorization": f"Bearer {tokens['access_token']}"}
    assert client.post("/api/v1/auth/logout", headers=headers).status_code == 200
    # The refresh token can no longer be exchanged.
    assert (
        client.post(
            "/api/v1/auth/refresh", cookies={REFRESH_COOKIE_NAME: refresh}
        ).status_code
        == 401
    )


def test_missing_refresh_cookie_rejected(client, seeded):
    # No cookie present at all → 401.
    assert client.post("/api/v1/auth/refresh").status_code == 401


def test_unknown_refresh_token_rejected(client, seeded):
    # A well-formed but never-issued token (different signature) is rejected.
    bogus = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwidHlwZSI6InJlZnJlc2gifQ.x"
    assert (
        client.post(
            "/api/v1/auth/refresh", cookies={REFRESH_COOKIE_NAME: bogus}
        ).status_code
        == 401
    )
