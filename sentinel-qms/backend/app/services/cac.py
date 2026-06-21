"""CAC / PIV (mutual-TLS) sign-in via a trusted reverse proxy.

A proxy (nginx/Apache/ALB) terminates the client-certificate handshake and
forwards the verification status + the client certificate (PEM) as headers. This
module trusts those headers ONLY when ``CLIENT_CERT_PROXY_AUTH`` *and*
``TRUST_PROXY_HEADERS`` are set (otherwise a direct client could spoof them),
parses the certificate, and extracts the user identity (email from the SAN /
Subject, display name from the CN). Provisioning, the domain allowlist, and
role mapping are shared with the OIDC/SAML path.
"""

from __future__ import annotations

from urllib.parse import unquote

from app.core.config import settings
from app.core.exceptions import AuthenticationError

# OID for the Microsoft UPN otherName SAN entry used on DoD CAC/PIV certs.
_UPN_OID = "1.3.6.1.4.1.311.20.2.3"
_VERIFY_OK = {"SUCCESS", "0", "OK"}


def is_enabled() -> bool:
    return bool(settings.CLIENT_CERT_PROXY_AUTH and settings.TRUST_PROXY_HEADERS)


def _load_cert(pem: str):  # noqa: ANN202 - cryptography x509 object
    from cryptography import x509

    text = unquote(pem).strip()
    # nginx forwards the PEM with literal spaces or tabs instead of newlines when
    # placed in a header; restore a parseable PEM block if needed.
    if "BEGIN CERTIFICATE" in text and "\n" not in text:
        body = (
            text.replace("-----BEGIN CERTIFICATE-----", "")
            .replace("-----END CERTIFICATE-----", "")
            .strip()
        )
        text = (
            "-----BEGIN CERTIFICATE-----\n"
            + "\n".join(body[i : i + 64] for i in range(0, len(body), 64))
            + "\n-----END CERTIFICATE-----\n"
        )
    try:
        return x509.load_pem_x509_certificate(text.encode("utf-8"))
    except Exception as exc:  # noqa: BLE001
        raise AuthenticationError("Unparseable client certificate.") from exc


def _email_from_cert(cert) -> str | None:  # noqa: ANN001
    from cryptography import x509
    from cryptography.x509.oid import ExtensionOID, NameOID

    # 1) SAN rfc822Name (email), or a UPN otherName (CAC/PIV).
    try:
        san = cert.extensions.get_extension_for_oid(ExtensionOID.SUBJECT_ALTERNATIVE_NAME).value
        emails = san.get_values_for_type(x509.RFC822Name)
        if emails:
            return emails[0]
        for other in san.get_values_for_type(x509.OtherName):
            if other.type_id.dotted_string == _UPN_OID:
                # UPN is a DER-encoded UTF8String; the principal is ascii-readable.
                raw = other.value
                upn = raw.decode("utf-8", "ignore").lstrip("\x0c").strip()
                upn = "".join(ch for ch in upn if ch.isprintable())
                if "@" in upn:
                    return upn
    except x509.ExtensionNotFound:
        pass
    # 2) Subject emailAddress.
    attrs = cert.subject.get_attributes_for_oid(NameOID.EMAIL_ADDRESS)
    if attrs:
        return attrs[0].value
    return None


def _name_from_cert(cert) -> str | None:  # noqa: ANN001
    from cryptography.x509.oid import NameOID

    cn = cert.subject.get_attributes_for_oid(NameOID.COMMON_NAME)
    return cn[0].value if cn else None


def extract_identity(verify_status: str | None, pem: str | None) -> dict:
    """Return ``{email, name}`` from a proxy-verified client cert, or raise."""
    if not is_enabled():
        raise AuthenticationError("CAC/PIV sign-in is not enabled on this deployment.")
    if (verify_status or "").strip().upper() not in _VERIFY_OK:
        raise AuthenticationError("Client certificate was not verified by the proxy.")
    if not pem:
        raise AuthenticationError("No client certificate was presented.")
    cert = _load_cert(pem)
    email = _email_from_cert(cert)
    if not email:
        raise AuthenticationError("Client certificate has no email/UPN identity.")
    return {"email": email, "name": _name_from_cert(cert)}
