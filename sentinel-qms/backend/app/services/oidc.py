"""OIDC federated SSO: ID-token verification + just-in-time user provisioning.

Verifies an IdP-issued ID token against the issuer's JWKS (RS256), enforces the
audience/issuer/expiry, optionally restricts by email domain, maps IdP group
claims to local roles, and provisions a local account on first login. The local
HS256 password path is unaffected; this is an additional sign-in route enabled
only when ``OIDC_ISSUER`` is configured (otherwise every call fails closed).
"""

from __future__ import annotations

import json
import threading
import time
import urllib.request
from typing import Any

from jose import jwt
from jose.exceptions import JWTError
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.exceptions import AuthenticationError, PermissionDeniedError
from app.core.net_guard import is_public_http_url
from app.models.user import Role, User

_HTTP_TIMEOUT = 5
_JWKS_TTL = 3600  # cache JWKS for an hour

_lock = threading.Lock()
_cache: dict[str, tuple[float, Any]] = {}


def _fetch_json(url: str) -> dict:
    if not is_public_http_url(url):
        raise AuthenticationError("OIDC endpoint must be a public https URL.")
    req = urllib.request.Request(url, headers={"Accept": "application/json"}, method="GET")
    with urllib.request.urlopen(req, timeout=_HTTP_TIMEOUT) as resp:  # noqa: S310
        return json.loads(resp.read().decode("utf-8"))


def _cached(key: str, loader) -> Any:  # noqa: ANN001
    now = time.monotonic()
    with _lock:
        hit = _cache.get(key)
        if hit and now - hit[0] < _JWKS_TTL:
            return hit[1]
    value = loader()
    with _lock:
        _cache[key] = (now, value)
    return value


def _jwks_uri() -> str:
    if settings.OIDC_JWKS_URI:
        return settings.OIDC_JWKS_URI
    disco = _cached(
        "discovery",
        lambda: _fetch_json(settings.OIDC_ISSUER.rstrip("/") + "/.well-known/openid-configuration"),
    )
    uri = disco.get("jwks_uri")
    if not uri:
        raise AuthenticationError("OIDC discovery document is missing jwks_uri.")
    return uri


def _signing_key(token: str) -> dict:
    """Return the JWK whose ``kid`` matches the token header (refresh once)."""
    try:
        kid = jwt.get_unverified_header(token).get("kid")
    except JWTError as exc:
        raise AuthenticationError("Malformed OIDC token header.") from exc

    def _find(jwks: dict) -> dict | None:
        for k in jwks.get("keys", []):
            if k.get("kid") == kid:
                return k
        return None

    jwks = _cached("jwks", lambda: _fetch_json(_jwks_uri()))
    key = _find(jwks)
    if key is None:
        # Key rotation: force a one-time refresh past the cache.
        jwks = _fetch_json(_jwks_uri())
        with _lock:
            _cache["jwks"] = (time.monotonic(), jwks)
        key = _find(jwks)
    if key is None:
        raise AuthenticationError("No matching OIDC signing key for token.")
    return key


def verify_id_token(token: str) -> dict[str, Any]:
    """Validate an IdP ID token and return its claims, or raise."""
    if not settings.OIDC_ISSUER or not settings.OIDC_CLIENT_ID:
        raise AuthenticationError("OIDC/SSO is not configured on this deployment.")
    key = _signing_key(token)
    try:
        claims = jwt.decode(
            token,
            key,
            algorithms=[key.get("alg", "RS256")],
            audience=settings.OIDC_CLIENT_ID,
            issuer=settings.OIDC_ISSUER,
        )
    except JWTError as exc:
        raise AuthenticationError(f"OIDC token rejected: {exc}") from exc
    return claims


def email_allowed(email: str) -> bool:
    domains = settings.OIDC_ALLOWED_DOMAINS
    if not domains:
        return True
    domain = email.rsplit("@", 1)[-1].lower()
    return domain in domains


def roles_for_groups(groups: list[str]) -> list[str]:
    """Map IdP groups to local role names via the configured map.

    Returns the configured default role when nothing maps.
    """
    mapping = settings.OIDC_GROUP_ROLE_MAP or {}
    mapped = [mapping[g] for g in groups if g in mapping]
    # De-dupe, preserve order.
    seen: dict[str, None] = {}
    for r in mapped:
        seen.setdefault(r, None)
    out = list(seen)
    return out or [settings.OIDC_DEFAULT_ROLE]


def _roles_by_name(db: Session, names: list[str]) -> list[Role]:
    if not names:
        return []
    rows = db.execute(select(Role).where(Role.name.in_(names))).scalars().all()
    return list(rows)


def resolve_or_provision_user(db: Session, claims: dict[str, Any]) -> User:
    """Find (or JIT-create) the local user for a verified set of OIDC claims."""
    email = (claims.get("email") or "").strip().lower()
    if not email:
        raise AuthenticationError("OIDC token has no email claim.")
    if not email_allowed(email):
        raise PermissionDeniedError("Your email domain is not permitted to sign in via SSO.")

    groups = claims.get(settings.OIDC_GROUP_CLAIM) or []
    if isinstance(groups, str):
        groups = [groups]

    user = db.execute(select(User).where(func.lower(User.email) == email)).scalar_one_or_none()

    if user is not None:
        if not user.is_active:
            raise AuthenticationError("Account is disabled.")
        # Re-sync roles from the IdP only for SSO-managed accounts that present
        # a group claim — never clobber a locally-managed user's roles.
        if user.is_sso and groups:
            mapped = _roles_by_name(db, roles_for_groups(groups))
            if mapped:
                user.roles = mapped
        return user

    if not settings.OIDC_AUTO_PROVISION:
        raise AuthenticationError("No account exists for this user and auto-provisioning is off.")

    full_name = (
        claims.get("name")
        or " ".join(filter(None, [claims.get("given_name"), claims.get("family_name")])).strip()
        or email.split("@")[0]
    )
    user = User(
        email=email,
        full_name=full_name,
        hashed_password=None,
        is_active=True,
        is_sso=True,
    )
    user.roles = _roles_by_name(db, roles_for_groups(groups))
    db.add(user)
    db.flush()
    return user
