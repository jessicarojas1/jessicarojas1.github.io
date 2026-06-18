"""Personal Access Token lifecycle, scope enforcement, and revocation."""

from __future__ import annotations


def _create_token(client, headers, *, name="ci", scopes=None, expires_in_days=None):
    body: dict = {"name": name, "scopes": scopes if scopes is not None else ["read"]}
    if expires_in_days is not None:
        body["expires_in_days"] = expires_in_days
    resp = client.post("/api/v1/tokens", json=body, headers=headers)
    assert resp.status_code == 201, resp.text
    return resp.json()


def test_create_returns_plaintext_once_and_prefix(client, auth_headers):
    h = auth_headers("admin")
    data = _create_token(client, h, name="export job", scopes=["read", "write"])
    assert data["token"].startswith("sntl_")
    assert data["token_prefix"].startswith("sntl_")
    # The full secret is never the same as the stored prefix.
    assert data["token"] != data["token_prefix"]
    assert data["scopes"] == ["read", "write"]
    assert data["active"] is True

    # Listing never includes the plaintext secret.
    listed = client.get("/api/v1/tokens", headers=h)
    assert listed.status_code == 200
    rows = listed.json()
    assert len(rows) == 1
    assert "token" not in rows[0]
    assert rows[0]["token_prefix"] == data["token_prefix"]


def test_unknown_scopes_dropped_defaults_readonly(client, auth_headers):
    h = auth_headers("admin")
    data = _create_token(client, h, scopes=["bogus", "WRITE"])
    # "bogus" dropped; "WRITE" normalized to "write".
    assert data["scopes"] == ["write"]

    empty = _create_token(client, h, name="empty", scopes=[])
    assert empty["scopes"] == ["read"]


def test_read_token_allows_get_blocks_writes(client, auth_headers):
    h = auth_headers("admin")
    data = _create_token(client, h, scopes=["read"])
    th = {"Authorization": f"Bearer {data['token']}"}

    # Safe method works — the token acts as its owner.
    me = client.get("/api/v1/auth/me", headers=th)
    assert me.status_code == 200
    assert me.json()["email"] == "admin@test.local"

    # Unsafe method is rejected for a read-only token.
    blocked = client.post("/api/v1/notifications/read-all", headers=th)
    assert blocked.status_code == 403


def test_write_token_allows_mutations(client, auth_headers):
    h = auth_headers("admin")
    data = _create_token(client, h, scopes=["read", "write"])
    th = {"Authorization": f"Bearer {data['token']}"}

    ok = client.post("/api/v1/notifications/read-all", headers=th)
    assert ok.status_code == 200


def test_api_token_cannot_manage_tokens(client, auth_headers):
    """A token must not be able to mint or revoke tokens for its owner."""
    h = auth_headers("admin")
    data = _create_token(client, h, scopes=["read", "write"])
    th = {"Authorization": f"Bearer {data['token']}"}

    resp = client.post("/api/v1/tokens", json={"name": "x", "scopes": ["read"]}, headers=th)
    assert resp.status_code == 403


def test_revoke_invalidates_token(client, auth_headers):
    h = auth_headers("admin")
    data = _create_token(client, h, scopes=["read"])
    th = {"Authorization": f"Bearer {data['token']}"}

    assert client.get("/api/v1/auth/me", headers=th).status_code == 200

    revoked = client.delete(f"/api/v1/tokens/{data['id']}", headers=h)
    assert revoked.status_code == 204

    # Revocation is idempotent.
    assert client.delete(f"/api/v1/tokens/{data['id']}", headers=h).status_code == 204

    # The token no longer authenticates.
    assert client.get("/api/v1/auth/me", headers=th).status_code == 401


def test_user_cannot_revoke_another_users_token(client, auth_headers):
    admin_h = auth_headers("admin")
    data = _create_token(client, admin_h, scopes=["read"])

    eng_h = auth_headers("engineer")
    resp = client.delete(f"/api/v1/tokens/{data['id']}", headers=eng_h)
    assert resp.status_code == 404

    # And cannot see it in their own list.
    listed = client.get("/api/v1/tokens", headers=eng_h)
    assert listed.status_code == 200
    assert listed.json() == []


def test_invalid_token_rejected(client):
    th = {"Authorization": "Bearer sntl_not-a-real-token"}
    assert client.get("/api/v1/auth/me", headers=th).status_code == 401
