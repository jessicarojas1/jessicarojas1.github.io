"""Refresh-token rotation, reuse detection, and revocation on logout."""

from __future__ import annotations


def _login(client, email="qe@test.local", password="EngPass123!"):
    r = client.post("/api/v1/auth/login", json={"username": email, "password": password})
    assert r.status_code == 200, r.text
    return r.json()


def test_refresh_rotates_token(client, seeded):
    tokens = _login(client)
    old_refresh = tokens["refresh_token"]

    r = client.post("/api/v1/auth/refresh", json={"refresh_token": old_refresh})
    assert r.status_code == 200
    new_refresh = r.json()["refresh_token"]
    # Rotation issues a brand-new refresh token.
    assert new_refresh != old_refresh
    # The new one works...
    assert (
        client.post("/api/v1/auth/refresh", json={"refresh_token": new_refresh}).status_code == 200
    )


def test_old_token_rejected_after_rotation(client, seeded):
    old_refresh = _login(client)["refresh_token"]
    client.post("/api/v1/auth/refresh", json={"refresh_token": old_refresh})
    # Reusing the rotated-away token is rejected.
    reuse = client.post("/api/v1/auth/refresh", json={"refresh_token": old_refresh})
    assert reuse.status_code == 401


def test_reuse_detection_revokes_whole_chain(client, seeded):
    """Replaying a rotated token burns its successor too (theft response)."""
    old_refresh = _login(client)["refresh_token"]
    good = client.post("/api/v1/auth/refresh", json={"refresh_token": old_refresh})
    successor = good.json()["refresh_token"]

    # Attacker replays the original (already rotated) token.
    assert (
        client.post("/api/v1/auth/refresh", json={"refresh_token": old_refresh}).status_code == 401
    )

    # The legitimate successor must now be invalid as well.
    assert client.post("/api/v1/auth/refresh", json={"refresh_token": successor}).status_code == 401


def test_logout_revokes_refresh_tokens(client, seeded):
    tokens = _login(client)
    headers = {"Authorization": f"Bearer {tokens['access_token']}"}
    assert client.post("/api/v1/auth/logout", headers=headers).status_code == 200
    # The refresh token can no longer be exchanged.
    assert (
        client.post(
            "/api/v1/auth/refresh", json={"refresh_token": tokens["refresh_token"]}
        ).status_code
        == 401
    )


def test_unknown_refresh_token_rejected(client, seeded):
    # A well-formed but never-issued token (different signature) is rejected.
    bogus = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwidHlwZSI6InJlZnJlc2gifQ.x"
    assert client.post("/api/v1/auth/refresh", json={"refresh_token": bogus}).status_code == 401
