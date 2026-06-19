"""OIDC federated SSO: claim→user provisioning, group/role mapping, exchange API."""

from __future__ import annotations

import pytest
from sqlalchemy import func, select

from app.core.config import settings
from app.core.exceptions import AuthenticationError, PermissionDeniedError
from app.models.user import User
from app.services import oidc


# --------------------------------------------------------------------------- #
# Domain allowlist + group→role mapping (pure logic)                          #
# --------------------------------------------------------------------------- #
def test_email_allowed(monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", [])
    assert oidc.email_allowed("anyone@example.com")
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    assert oidc.email_allowed("jane@corp.mil")
    assert not oidc.email_allowed("jane@gmail.com")


def test_roles_for_groups(monkeypatch):
    monkeypatch.setattr(
        settings, "OIDC_GROUP_ROLE_MAP", {"qms-admins": "Admin", "eng": "Quality Engineer"}
    )
    monkeypatch.setattr(settings, "OIDC_DEFAULT_ROLE", "Read-Only")
    assert oidc.roles_for_groups(["qms-admins", "eng"]) == ["Admin", "Quality Engineer"]
    # Unknown groups fall back to the default role.
    assert oidc.roles_for_groups(["nope"]) == ["Read-Only"]
    assert oidc.roles_for_groups([]) == ["Read-Only"]


# --------------------------------------------------------------------------- #
# JIT provisioning                                                            #
# --------------------------------------------------------------------------- #
def test_provisions_new_user_with_mapped_roles(db_session, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    monkeypatch.setattr(settings, "OIDC_GROUP_ROLE_MAP", {"qms-admins": "Admin"})
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", True)

    user = oidc.resolve_or_provision_user(
        db_session,
        {"email": "NewPerson@corp.mil", "name": "New Person", "groups": ["qms-admins"]},
    )
    assert user.id is not None
    assert user.email == "newperson@corp.mil"
    assert user.is_sso is True
    assert user.hashed_password is None
    assert user.role_names == ["Admin"]


def test_disallowed_domain_rejected(db_session, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    with pytest.raises(PermissionDeniedError):
        oidc.resolve_or_provision_user(db_session, {"email": "x@gmail.com"})


def test_no_autoprovision_for_unknown_user(db_session, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", [])
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", False)
    with pytest.raises(AuthenticationError):
        oidc.resolve_or_provision_user(db_session, {"email": "ghost@corp.mil"})


def test_existing_local_user_roles_untouched(db_session, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_GROUP_ROLE_MAP", {"qms-admins": "Admin"})
    # Existing local (non-SSO) engineer signs in via SSO; roles must NOT change.
    user = oidc.resolve_or_provision_user(
        db_session, {"email": "qe@test.local", "groups": ["qms-admins"]}
    )
    assert user.email == "qe@test.local"
    assert "Admin" not in user.role_names  # local management preserved


def test_verify_id_token_requires_configuration(monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "")
    with pytest.raises(AuthenticationError):
        oidc.verify_id_token("whatever")


# --------------------------------------------------------------------------- #
# Exchange endpoint (token verification mocked)                               #
# --------------------------------------------------------------------------- #
def test_oidc_exchange_issues_session(client, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    monkeypatch.setattr(settings, "OIDC_GROUP_ROLE_MAP", {"eng": "Quality Engineer"})
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", True)
    monkeypatch.setattr(
        oidc,
        "verify_id_token",
        lambda _token: {"email": "sso.user@corp.mil", "name": "SSO User", "groups": ["eng"]},
    )

    resp = client.post("/api/v1/auth/oidc/exchange", json={"id_token": "fake.jwt.token"})
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["access_token"] and body["refresh_token"]

    # The issued session works and identifies the provisioned user.
    me = client.get("/api/v1/auth/me", headers={"Authorization": f"Bearer {body['access_token']}"})
    assert me.status_code == 200
    assert me.json()["email"] == "sso.user@corp.mil"


def test_oidc_exchange_rejects_invalid_token(client, seeded, monkeypatch):
    def _boom(_token):
        raise AuthenticationError("OIDC token rejected")

    monkeypatch.setattr(oidc, "verify_id_token", _boom)
    resp = client.post("/api/v1/auth/oidc/exchange", json={"id_token": "bad"})
    assert resp.status_code == 401


def test_provisioned_sso_user_has_no_password_login(client, db_session, seeded, monkeypatch):
    """An SSO-only account (no password) cannot log in via the password grant."""
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", [])
    oidc.resolve_or_provision_user(db_session, {"email": "ssoonly@corp.mil", "name": "SSO Only"})
    db_session.commit()
    created = db_session.execute(
        select(User).where(func.lower(User.email) == "ssoonly@corp.mil")
    ).scalar_one()
    assert created.hashed_password is None
    resp = client.post(
        "/api/v1/auth/login", json={"username": "ssoonly@corp.mil", "password": "anything"}
    )
    assert resp.status_code == 401


# --------------------------------------------------------------------------- #
# Authorization-code (browser) flow                                           #
# --------------------------------------------------------------------------- #
def test_safe_redirect_blocks_open_redirects():
    assert oidc._safe_redirect("/dashboard") == "/dashboard"
    assert oidc._safe_redirect("//evil.com") == "/"
    assert oidc._safe_redirect("https://evil.com") == "/"
    assert oidc._safe_redirect(None) == "/"


def test_state_roundtrip():
    token = oidc.issue_state("/risks", "nonce-abc")
    payload = oidc.verify_state(token)
    assert payload["redirect"] == "/risks"
    assert payload["nonce"] == "nonce-abc"


def test_sso_info_reflects_configuration(client, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "")
    assert client.get("/api/v1/auth/sso/info").json()["enabled"] is False
    monkeypatch.setattr(settings, "OIDC_ISSUER", "https://idp.example.com")
    monkeypatch.setattr(settings, "OIDC_CLIENT_ID", "cid")
    assert client.get("/api/v1/auth/sso/info").json()["enabled"] is True


def test_oidc_login_redirects_to_idp(client, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "https://idp.example.com")
    monkeypatch.setattr(settings, "OIDC_CLIENT_ID", "cid")
    monkeypatch.setattr(
        oidc,
        "build_authorize_url",
        lambda ru, st, n: f"https://idp.example.com/authorize?state={st}",
    )
    resp = client.get("/api/v1/auth/oidc/login?redirect=/risks", follow_redirects=False)
    assert resp.status_code == 302
    assert resp.headers["location"].startswith("https://idp.example.com/authorize")


def test_oidc_login_disabled_is_rejected(client, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "")
    resp = client.get("/api/v1/auth/oidc/login", follow_redirects=False)
    assert resp.status_code == 401


def test_oidc_callback_success_hands_tokens_to_spa(client, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "https://idp.example.com")
    monkeypatch.setattr(settings, "OIDC_CLIENT_ID", "cid")
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", True)
    monkeypatch.setattr(oidc, "exchange_code", lambda code, ru: "fake.id.token")
    monkeypatch.setattr(
        oidc,
        "verify_id_token",
        lambda _t: {"email": "browser.sso@corp.mil", "name": "Browser SSO", "nonce": "n1"},
    )
    state = oidc.issue_state("/dashboard", "n1")
    resp = client.get(f"/api/v1/auth/oidc/callback?code=abc&state={state}", follow_redirects=False)
    assert resp.status_code == 302
    loc = resp.headers["location"]
    assert "/dashboard#access_token=" in loc
    assert "refresh_token=" in loc


def test_oidc_callback_error_redirects_to_login(client, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "https://idp.example.com")
    monkeypatch.setattr(settings, "OIDC_CLIENT_ID", "cid")
    resp = client.get("/api/v1/auth/oidc/callback?error=access_denied", follow_redirects=False)
    assert resp.status_code == 302
    assert "/login?sso_error=" in resp.headers["location"]


def test_oidc_callback_nonce_mismatch_denied(client, seeded, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ISSUER", "https://idp.example.com")
    monkeypatch.setattr(settings, "OIDC_CLIENT_ID", "cid")
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", [])
    monkeypatch.setattr(oidc, "exchange_code", lambda code, ru: "fake.id.token")
    monkeypatch.setattr(
        oidc, "verify_id_token", lambda _t: {"email": "x@corp.mil", "nonce": "WRONG"}
    )
    state = oidc.issue_state("/", "n1")
    resp = client.get(f"/api/v1/auth/oidc/callback?code=abc&state={state}", follow_redirects=False)
    assert resp.status_code == 302
    assert "sso_error=" in resp.headers["location"]
