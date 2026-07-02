"""Password hashing and JWT (access + refresh) token management."""

from __future__ import annotations

import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

import bcrypt
from fastapi.security import OAuth2PasswordBearer
from jose import JWTError, jwt

from app.core.config import settings
from app.core.exceptions import AuthenticationError

# bcrypt only inspects the first 72 bytes of a password; longer inputs are
# truncated (matching passlib's historical behavior) so hashing never raises on
# bcrypt >= 4.1 / 5.x. We call ``bcrypt`` directly rather than through the
# unmaintained passlib wrapper, whose backend-version probe is broken against
# modern bcrypt releases. The produced ``$2b$`` hashes are format-compatible with
# any prior passlib-generated hashes, so verification is backward compatible.
_BCRYPT_MAX_BYTES = 72

oauth2_scheme = OAuth2PasswordBearer(
    tokenUrl=f"{settings.API_V1_PREFIX}/auth/login", auto_error=False
)

ACCESS_TOKEN_TYPE = "access"
REFRESH_TOKEN_TYPE = "refresh"


def _bcrypt_secret(password: str) -> bytes:
    return password.encode("utf-8")[:_BCRYPT_MAX_BYTES]


def hash_password(password: str) -> str:
    return bcrypt.hashpw(_bcrypt_secret(password), bcrypt.gensalt()).decode("ascii")


def verify_password(plain: str, hashed: str) -> bool:
    try:
        return bcrypt.checkpw(_bcrypt_secret(plain), hashed.encode("ascii"))
    except (ValueError, TypeError):
        return False


def _create_token(
    subject: str,
    token_type: str,
    expires_delta: timedelta,
    extra: dict[str, Any] | None = None,
) -> str:
    now = datetime.now(UTC)
    payload: dict[str, Any] = {
        "sub": str(subject),
        "type": token_type,
        "iat": int(now.timestamp()),
        "exp": int((now + expires_delta).timestamp()),
        "jti": uuid.uuid4().hex,
    }
    if extra:
        payload.update(extra)
    return jwt.encode(payload, settings.JWT_SECRET, algorithm=settings.JWT_ALGORITHM)


def create_access_token(subject: str, roles: list[str], **extra: Any) -> str:
    return _create_token(
        subject,
        ACCESS_TOKEN_TYPE,
        timedelta(minutes=settings.ACCESS_TOKEN_EXPIRE_MINUTES),
        {"roles": roles, **extra},
    )


def create_refresh_token(subject: str) -> str:
    return _create_token(
        subject,
        REFRESH_TOKEN_TYPE,
        timedelta(days=settings.REFRESH_TOKEN_EXPIRE_DAYS),
    )


def new_refresh_token(subject: str) -> tuple[str, str, datetime]:
    """Mint a refresh token, returning ``(token, jti, expires_at)``.

    The ``jti`` is the server-side handle used to persist, rotate, and revoke the
    token; ``expires_at`` mirrors the encoded ``exp`` claim.
    """
    now = datetime.now(UTC)
    expires_at = now + timedelta(days=settings.REFRESH_TOKEN_EXPIRE_DAYS)
    jti = uuid.uuid4().hex
    payload: dict[str, Any] = {
        "sub": str(subject),
        "type": REFRESH_TOKEN_TYPE,
        "iat": int(now.timestamp()),
        "exp": int(expires_at.timestamp()),
        "jti": jti,
    }
    token = jwt.encode(payload, settings.JWT_SECRET, algorithm=settings.JWT_ALGORITHM)
    return token, jti, expires_at


def decode_token(token: str, *, expected_type: str | None = None) -> dict[str, Any]:
    try:
        payload = jwt.decode(token, settings.JWT_SECRET, algorithms=[settings.JWT_ALGORITHM])
    except JWTError as exc:
        raise AuthenticationError("Could not validate credentials.") from exc
    if expected_type and payload.get("type") != expected_type:
        raise AuthenticationError(f"Expected a {expected_type} token.")
    return payload


def verify_oidc_token(token: str) -> dict[str, Any]:
    """Validate a federated-SSO (OIDC) ID token and return its claims.

    Delegates to :mod:`app.services.oidc`, which verifies the token against the
    issuer's JWKS (RS256) and enforces audience/issuer/expiry. Raises
    :class:`AuthenticationError` when SSO is not configured or the token is
    invalid. (Imported lazily to avoid an import cycle.)
    """
    from app.services import oidc

    return oidc.verify_id_token(token)
