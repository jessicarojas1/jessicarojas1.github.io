"""SAML 2.0 SSO: signed-assertion verification, conditions, and the ACS endpoint.

Builds a real RSA-signed SAML Response with signxml so verification is exercised
end-to-end (not mocked), including tamper / audience / expiry rejection.
"""

from __future__ import annotations

import base64
from datetime import UTC, datetime, timedelta

import pytest

from app.core.config import settings
from app.core.exceptions import AuthenticationError
from app.services import saml

# Skip the whole module if the optional SAML stack is not installed.
pytest.importorskip("signxml")
pytest.importorskip("lxml")

SP_ENTITY = "https://sentinel-qms.example.com/sp"
IDP_ENTITY = "https://idp.example.com/saml"


@pytest.fixture(scope="module")
def keypair() -> tuple[str, str]:
    """Return (private_key_pem, cert_pem) for a throwaway self-signed signer."""
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = issuer = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Test IdP")])
    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(issuer)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.now(UTC) - timedelta(days=1))
        .not_valid_after(datetime.now(UTC) + timedelta(days=3650))
        .sign(key, hashes.SHA256())
    )
    key_pem = key.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.TraditionalOpenSSL,
        serialization.NoEncryption(),
    ).decode()
    cert_pem = cert.public_bytes(serialization.Encoding.PEM).decode()
    return key_pem, cert_pem


def _build_signed_response(
    keypair: tuple[str, str],
    *,
    email: str = "sam.l@corp.mil",
    audience: str = SP_ENTITY,
    not_on_or_after: datetime | None = None,
    tamper_email: str | None = None,
) -> str:
    from lxml import etree
    from signxml import XMLSigner

    key_pem, cert_pem = keypair
    now = datetime.now(UTC)
    na = (not_on_or_after or (now + timedelta(minutes=5))).strftime("%Y-%m-%dT%H:%M:%SZ")
    nb = (now - timedelta(minutes=5)).strftime("%Y-%m-%dT%H:%M:%SZ")
    issued = now.strftime("%Y-%m-%dT%H:%M:%SZ")
    xml = f"""<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
        xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
        ID="_resp1" Version="2.0" IssueInstant="{issued}">
      <saml:Issuer>{IDP_ENTITY}</saml:Issuer>
      <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
      </samlp:Status>
      <saml:Assertion ID="_assert1" Version="2.0" IssueInstant="{issued}">
        <saml:Issuer>{IDP_ENTITY}</saml:Issuer>
        <saml:Subject><saml:NameID>{email}</saml:NameID></saml:Subject>
        <saml:Conditions NotBefore="{nb}" NotOnOrAfter="{na}">
          <saml:AudienceRestriction>
            <saml:Audience>{audience}</saml:Audience>
          </saml:AudienceRestriction>
        </saml:Conditions>
        <saml:AttributeStatement>
          <saml:Attribute Name="displayName">
            <saml:AttributeValue>Sam L</saml:AttributeValue>
          </saml:Attribute>
          <saml:Attribute Name="groups">
            <saml:AttributeValue>eng</saml:AttributeValue>
            <saml:AttributeValue>qms-admins</saml:AttributeValue>
          </saml:Attribute>
        </saml:AttributeStatement>
      </saml:Assertion>
    </samlp:Response>"""
    root = etree.fromstring(xml.encode())
    signed = XMLSigner().sign(root, key=key_pem.encode(), cert=cert_pem.encode())
    if tamper_email:
        # Mutate the signed document after signing → signature must no longer verify.
        nid = signed.find(
            ".//saml:Subject/saml:NameID",
            {"saml": "urn:oasis:names:tc:SAML:2.0:assertion"},
        )
        nid.text = tamper_email
    return base64.b64encode(etree.tostring(signed)).decode()


@pytest.fixture()
def saml_configured(keypair, monkeypatch):
    _, cert_pem = keypair
    monkeypatch.setattr(settings, "SAML_IDP_SSO_URL", "https://idp.example.com/sso")
    monkeypatch.setattr(settings, "SAML_IDP_CERT", cert_pem)
    monkeypatch.setattr(settings, "SAML_IDP_ENTITY_ID", IDP_ENTITY)
    monkeypatch.setattr(settings, "SAML_SP_ENTITY_ID", SP_ENTITY)
    monkeypatch.setattr(settings, "SAML_GROUP_ATTRIBUTE", "groups")
    monkeypatch.setattr(settings, "SAML_NAME_ATTRIBUTE", "displayName")
    monkeypatch.setattr(settings, "SAML_EMAIL_ATTRIBUTE", "")
    return cert_pem


# --------------------------------------------------------------------------- #
# Verification                                                                #
# --------------------------------------------------------------------------- #
def test_verifies_and_extracts_claims(keypair, saml_configured):
    resp = _build_signed_response(keypair)
    claims = saml.parse_and_verify_response(resp)
    assert claims["email"] == "sam.l@corp.mil"
    assert claims["name"] == "Sam L"
    assert claims["groups"] == ["eng", "qms-admins"]


def test_tampered_assertion_rejected(keypair, saml_configured):
    resp = _build_signed_response(keypair, tamper_email="attacker@evil.com")
    with pytest.raises(AuthenticationError):
        saml.parse_and_verify_response(resp)


def test_wrong_signing_cert_rejected(keypair, saml_configured, monkeypatch):
    # A different cert than the one that signed must fail verification.
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID

    other = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Other")])
    cert = (
        x509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(other.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.now(UTC) - timedelta(days=1))
        .not_valid_after(datetime.now(UTC) + timedelta(days=10))
        .sign(other, hashes.SHA256())
    )
    monkeypatch.setattr(
        settings, "SAML_IDP_CERT", cert.public_bytes(serialization.Encoding.PEM).decode()
    )
    with pytest.raises(AuthenticationError):
        saml.parse_and_verify_response(_build_signed_response(keypair))


def test_wrong_audience_rejected(keypair, saml_configured):
    resp = _build_signed_response(keypair, audience="https://someone-else/sp")
    with pytest.raises(AuthenticationError):
        saml.parse_and_verify_response(resp)


def test_expired_assertion_rejected(keypair, saml_configured):
    resp = _build_signed_response(keypair, not_on_or_after=datetime.now(UTC) - timedelta(hours=1))
    with pytest.raises(AuthenticationError):
        saml.parse_and_verify_response(resp)


# --------------------------------------------------------------------------- #
# Endpoints                                                                   #
# --------------------------------------------------------------------------- #
def test_authn_request_redirect_contains_samlrequest(saml_configured):
    url = saml.build_authn_request("https://app/acs", "relay123")
    assert url.startswith("https://idp.example.com/sso?")
    assert "SAMLRequest=" in url and "RelayState=relay123" in url


def test_sso_info_reports_saml(client, saml_configured):
    info = client.get("/api/v1/auth/sso/info").json()
    assert info["saml"] is True and info["enabled"] is True


def test_saml_metadata_served(client, saml_configured):
    resp = client.get("/api/v1/auth/saml/metadata")
    assert resp.status_code == 200
    assert SP_ENTITY in resp.text and "AssertionConsumerService" in resp.text


def test_acs_signs_in_and_provisions(client, seeded, keypair, saml_configured, monkeypatch):
    monkeypatch.setattr(settings, "OIDC_ALLOWED_DOMAINS", ["corp.mil"])
    monkeypatch.setattr(settings, "OIDC_GROUP_ROLE_MAP", {"qms-admins": "Admin"})
    monkeypatch.setattr(settings, "OIDC_AUTO_PROVISION", True)
    resp = client.post(
        "/api/v1/auth/saml/acs",
        data={"SAMLResponse": _build_signed_response(keypair), "RelayState": ""},
        follow_redirects=False,
    )
    assert resp.status_code == 302
    assert "#access_token=" in resp.headers["location"]


def test_acs_rejects_tampered_response(client, seeded, keypair, saml_configured):
    resp = client.post(
        "/api/v1/auth/saml/acs",
        data={"SAMLResponse": _build_signed_response(keypair, tamper_email="x@evil.com")},
        follow_redirects=False,
    )
    assert resp.status_code == 302
    assert "sso_error=" in resp.headers["location"]
