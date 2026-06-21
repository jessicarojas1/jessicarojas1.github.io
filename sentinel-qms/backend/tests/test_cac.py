"""CAC/PIV (mTLS reverse-proxy) sign-in: cert identity extraction + login route."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from urllib.parse import quote

import pytest

from app.core.config import settings
from app.core.exceptions import AuthenticationError
from app.services import cac

pytest.importorskip("cryptography")

VERIFY_HDR = "X-SSL-Client-Verify"
PEM_HDR = "X-SSL-Client-Cert"


def _client_cert_pem(email: str = "jane.operator@corp.mil", cn: str = "Jane Operator") -> str:
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, cn)])
    cert = (
        x509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.now(UTC) - timedelta(days=1))
        .not_valid_after(datetime.now(UTC) + timedelta(days=365))
        .add_extension(x509.SubjectAlternativeName([x509.RFC822Name(email)]), critical=False)
        .sign(key, hashes.SHA256())
    )
    return cert.public_bytes(serialization.Encoding.PEM).decode()


@pytest.fixture()
def cac_enabled(monkeypatch):
    monkeypatch.setattr(settings, "CLIENT_CERT_PROXY_AUTH", True)
    monkeypatch.setattr(settings, "TRUST_PROXY_HEADERS", True)
    monkeypatch.setattr(settings, "CLIENT_CERT_VERIFY_HEADER", VERIFY_HDR)
    monkeypatch.setattr(settings, "CLIENT_CERT_PEM_HEADER", PEM_HDR)


# --------------------------------------------------------------------------- #
# Identity extraction                                                         #
# --------------------------------------------------------------------------- #
def test_extracts_email_and_name(cac_enabled):
    ident = cac.extract_identity("SUCCESS", _client_cert_pem())
    assert ident["email"] == "jane.operator@corp.mil"
    assert ident["name"] == "Jane Operator"


def test_url_encoded_pem_accepted(cac_enabled):
    ident = cac.extract_identity("SUCCESS", quote(_client_cert_pem()))
    assert ident["email"] == "jane.operator@corp.mil"


def test_unverified_cert_rejected(cac_enabled):
    with pytest.raises(AuthenticationError):
        cac.extract_identity("FAILED", _client_cert_pem())


def test_missing_cert_rejected(cac_enabled):
    with pytest.raises(AuthenticationError):
        cac.extract_identity("SUCCESS", None)


def test_disabled_without_proxy_trust(monkeypatch):
    monkeypatch.setattr(settings, "CLIENT_CERT_PROXY_AUTH", True)
    monkeypatch.setattr(settings, "TRUST_PROXY_HEADERS", False)
    assert cac.is_enabled() is False
    with pytest.raises(AuthenticationError):
        cac.extract_identity("SUCCESS", _client_cert_pem())


# --------------------------------------------------------------------------- #
# Login endpoint                                                              #
# --------------------------------------------------------------------------- #
def test_sso_info_reports_cac(client, cac_enabled):
    assert client.get("/api/v1/auth/sso/info").json()["cac"] is True


def test_cac_login_provisions_and_issues_session(client, seeded, cac_enabled, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", True)
    resp = client.get(
        "/api/v1/auth/cac/login",
        headers={VERIFY_HDR: "SUCCESS", PEM_HDR: quote(_client_cert_pem())},
        follow_redirects=False,
    )
    assert resp.status_code == 302
    assert "#access_token=" in resp.headers["location"]


def test_cac_login_denied_when_unverified(client, seeded, cac_enabled):
    resp = client.get(
        "/api/v1/auth/cac/login",
        headers={VERIFY_HDR: "NONE", PEM_HDR: quote(_client_cert_pem())},
        follow_redirects=False,
    )
    assert resp.status_code == 302
    assert "sso_error=" in resp.headers["location"]
