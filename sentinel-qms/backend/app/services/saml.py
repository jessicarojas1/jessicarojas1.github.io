"""SAML 2.0 SP-initiated Web Browser SSO.

Builds an ``AuthnRequest`` (HTTP-Redirect binding), verifies the IdP's signed
``Response``/``Assertion`` (HTTP-POST binding), and extracts the subject + group
attributes for provisioning. Signature verification uses :mod:`signxml`; we only
ever read data from the *verified subtree* returned by the verifier, which is the
standard mitigation against XML Signature Wrapping (XSW).

User provisioning, the email-domain allowlist, and group→role mapping are shared
with the OIDC path (:func:`app.services.oidc.resolve_or_provision_user`) so there
is a single federation policy.
"""

from __future__ import annotations

import base64
import uuid
import zlib
from datetime import UTC, datetime, timedelta
from urllib.parse import urlencode

from app.core.config import settings
from app.core.exceptions import AuthenticationError

_SAML_PROTO = "urn:oasis:names:tc:SAML:2.0:protocol"
_SAML_ASSERT = "urn:oasis:names:tc:SAML:2.0:assertion"
_NS = {"samlp": _SAML_PROTO, "saml": _SAML_ASSERT}
_CLOCK_SKEW = timedelta(minutes=5)


def is_enabled() -> bool:
    return bool(settings.SAML_IDP_SSO_URL and settings.SAML_IDP_CERT and settings.SAML_SP_ENTITY_ID)


def _now() -> datetime:
    return datetime.now(UTC)


def _parse_instant(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def build_authn_request(acs_url: str, relay_state: str) -> str:
    """Return the IdP SSO redirect URL (HTTP-Redirect binding) for SP-initiated login."""
    req_id = "_" + uuid.uuid4().hex
    issued = _now().strftime("%Y-%m-%dT%H:%M:%SZ")
    xml = (
        '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
        'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" '
        f'ID="{req_id}" Version="2.0" IssueInstant="{issued}" '
        f'Destination="{settings.SAML_IDP_SSO_URL}" '
        f'AssertionConsumerServiceURL="{acs_url}" '
        'ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
        f"<saml:Issuer>{settings.SAML_SP_ENTITY_ID}</saml:Issuer>"
        "</samlp:AuthnRequest>"
    )
    # HTTP-Redirect binding: raw-DEFLATE, base64, URL-encode.
    deflated = zlib.compressobj(9, zlib.DEFLATED, -15)
    packed = deflated.compress(xml.encode("utf-8")) + deflated.flush()
    saml_request = base64.b64encode(packed).decode("ascii")
    query = urlencode({"SAMLRequest": saml_request, "RelayState": relay_state})
    sep = "&" if "?" in settings.SAML_IDP_SSO_URL else "?"
    return f"{settings.SAML_IDP_SSO_URL}{sep}{query}"


def _verified_assertion(saml_response_b64: str):  # noqa: ANN202 - lxml element
    """Verify the response signature and return the signed Assertion element."""
    try:
        from lxml import etree
        from signxml import XMLVerifier
    except ImportError as exc:  # pragma: no cover - dependency guard
        raise AuthenticationError("SAML support is not installed on this deployment.") from exc

    try:
        data = base64.b64decode(saml_response_b64)
    except Exception as exc:  # noqa: BLE001
        raise AuthenticationError("Malformed SAMLResponse.") from exc

    try:
        # Verify against the IdP cert; only the returned subtree is trusted.
        verified = XMLVerifier().verify(data, x509_cert=settings.SAML_IDP_CERT).signed_xml
    except Exception as exc:  # noqa: BLE001 - signxml raises many subclasses
        raise AuthenticationError("SAML signature verification failed.") from exc

    localname = etree.QName(verified.tag).localname
    assertion = (
        verified if localname == "Assertion" else verified.find(".//saml:Assertion", _NS)
    )
    if assertion is None:
        raise AuthenticationError("No signed SAML assertion found in the response.")
    return assertion


def _validate_conditions(assertion) -> None:  # noqa: ANN001
    conditions = assertion.find("saml:Conditions", _NS)
    if conditions is None:
        return
    now = _now()
    nb = _parse_instant(conditions.get("NotBefore"))
    na = _parse_instant(conditions.get("NotOnOrAfter"))
    if nb and now + _CLOCK_SKEW < nb:
        raise AuthenticationError("SAML assertion is not yet valid.")
    if na and now - _CLOCK_SKEW >= na:
        raise AuthenticationError("SAML assertion has expired.")
    audiences = [
        el.text
        for el in assertion.findall("saml:Conditions/saml:AudienceRestriction/saml:Audience", _NS)
        if el.text
    ]
    if audiences and settings.SAML_SP_ENTITY_ID not in audiences:
        raise AuthenticationError("SAML assertion audience does not match this service.")


def parse_and_verify_response(saml_response_b64: str) -> dict:
    """Return ``{email, name, groups}`` from a verified SAML response, or raise."""
    assertion = _verified_assertion(saml_response_b64)
    _validate_conditions(assertion)

    if settings.SAML_IDP_ENTITY_ID:
        issuer = assertion.findtext("saml:Issuer", namespaces=_NS)
        if issuer and issuer != settings.SAML_IDP_ENTITY_ID:
            raise AuthenticationError("SAML assertion issuer mismatch.")

    attrs: dict[str, list[str]] = {}
    for attr in assertion.findall("saml:AttributeStatement/saml:Attribute", _NS):
        name = attr.get("Name") or attr.get("FriendlyName")
        if not name:
            continue
        attrs[name] = [
            v.text for v in attr.findall("saml:AttributeValue", _NS) if v.text is not None
        ]

    name_id = assertion.findtext("saml:Subject/saml:NameID", namespaces=_NS)
    email_attr = settings.SAML_EMAIL_ATTRIBUTE
    email = (attrs.get(email_attr, [None])[0] if email_attr else None) or name_id
    if not email:
        raise AuthenticationError("SAML assertion has no email/NameID.")

    display = attrs.get(settings.SAML_NAME_ATTRIBUTE, [None])[0]
    groups = attrs.get(settings.SAML_GROUP_ATTRIBUTE, [])
    return {"email": email, "name": display, "groups": groups}


def sp_metadata(acs_url: str) -> str:
    """Minimal SP metadata XML for registering this service with the IdP."""
    return (
        '<?xml version="1.0"?>'
        '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" '
        f'entityID="{settings.SAML_SP_ENTITY_ID}">'
        "<md:SPSSODescriptor protocolSupportEnumeration="
        '"urn:oasis:names:tc:SAML:2.0:protocol" '
        'AuthnRequestsSigned="false" WantAssertionsSigned="true">'
        "<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress"
        "</md:NameIDFormat>"
        "<md:AssertionConsumerService "
        'Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" '
        f'Location="{acs_url}" index="0" isDefault="true"/>'
        "</md:SPSSODescriptor></md:EntityDescriptor>"
    )
