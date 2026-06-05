"""Password hashing and JWT (access + refresh) token management."""
from __future__ import annotations

import uuid
from datetime import datetime, timedelta, timezone
from typing import Any

from fastapi.security import OAuth2PasswordBearer
from jose import JWTError, jwt
from passlib.context import CryptContext

from app.core.config import settings
from app.core.exceptions import AuthenticationError

# bcrypt has a 72-byte limit; passlib truncates with the bcrypt backend.
pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")

oauth2_scheme = OAuth2PasswordBearer(
    tokenUrl=f"{settings.API_V1_PREFIX}/auth/login", auto_error=False
)

ACCESS_TOKEN_TYPE = "access"
REFRESH_TOKEN_TYPE = "refresh"


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(plain: str, hashed: str) -> bool:
    try:
        return pwd_context.verify(plain, hashed)
    except ValueError:
        return False


def _create_token(
    subject: str,
    token_type: str,
    expires_delta: timedelta,
    extra: dict[str, Any] | None = None,
) -> str:
    now = datetime.now(timezone.utc)
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


def decode_token(token: str, *, expected_type: str | None = None) -> dict[str, Any]:
    try:
        payload = jwt.decode(token, settings.JWT_SECRET, algorithms=[settings.JWT_ALGORITHM])
    except JWTError as exc:
        raise AuthenticationError("Could not validate credentials.") from exc
    if expected_type and payload.get("type") != expected_type:
        raise AuthenticationError(f"Expected a {expected_type} token.")
    return payload


def verify_oidc_token(token: str) -> dict[str, Any]:
    """Pluggable federal SSO (OIDC / CAC-PIV) verification path — stubbed.

    In a real deployment this validates the token against the IdP's JWKS,
    checks issuer/audience, and maps subject claims to a local user.  The
    local HS256 path above is fully functional; this stub raises until an
    issuer is configured so the wiring is present without false security.
    """
    if not settings.OIDC_ISSUER:
        raise AuthenticationError("OIDC/SSO is not configured on this deployment.")
    raise AuthenticationError("OIDC token verification is not yet enabled.")
