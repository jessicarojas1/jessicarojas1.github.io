"""Authentication and token flow tests."""

from __future__ import annotations


def test_health_ok(client):
    resp = client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] == "ok"
    assert body["database"]["connected"] is True


def test_login_success_and_me(client, seeded, auth_headers):
    headers = auth_headers("admin")
    resp = client.get("/api/v1/auth/me", headers=headers)
    assert resp.status_code == 200
    assert resp.json()["email"] == "admin@test.local"


def test_login_wrong_password(client, seeded):
    resp = client.post(
        "/api/v1/auth/login",
        json={"username": "admin@test.local", "password": "nope"},
    )
    assert resp.status_code == 401


def test_malformed_body_returns_422_not_500(client, seeded):
    # A non-JSON (form-encoded) body must produce a clean validation error,
    # never a serialization 500 when the handler renders the raw-bytes input.
    resp = client.post(
        "/api/v1/auth/login",
        data={"username": "admin@test.local", "password": "nope"},
    )
    assert resp.status_code == 422, resp.text
    assert resp.json()["error"]["code"] == "validation_error"


def test_refresh_token(client, seeded):
    # The refresh token is delivered as an HttpOnly cookie; the TestClient's
    # cookie jar carries it on the subsequent /auth/refresh call.
    login = client.post(
        "/api/v1/auth/login",
        json={"username": "qe@test.local", "password": "EngPass123!"},
    )
    assert login.status_code == 200, login.text
    assert "refresh_token" not in login.json()
    resp = client.post("/api/v1/auth/refresh")
    assert resp.status_code == 200
    assert "access_token" in resp.json()


def test_protected_requires_token(client, seeded):
    resp = client.get("/api/v1/nonconformances")
    assert resp.status_code == 401


def test_logout_denies_current_access_token(client, seeded):
    """True logout: the access token presented at logout is revoked immediately."""
    login = client.post(
        "/api/v1/auth/login",
        json={"username": "qe@test.local", "password": "EngPass123!"},
    )
    assert login.status_code == 200, login.text
    headers = {"Authorization": f"Bearer {login.json()['access_token']}"}
    # The token works before logout.
    assert client.get("/api/v1/auth/me", headers=headers).status_code == 200
    # Log out, then the SAME access token must be rejected (denylisted by jti),
    # not merely left valid until natural expiry.
    assert client.post("/api/v1/auth/logout", headers=headers).status_code == 200
    assert client.get("/api/v1/auth/me", headers=headers).status_code == 401
