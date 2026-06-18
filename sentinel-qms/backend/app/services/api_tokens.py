"""Personal Access Token generation, hashing, and authentication.

Token format: ``sntl_<random>`` where ``<random>`` is URL-safe base64. We store
only ``SHA-256(full_token)`` plus a short non-secret prefix for identification.
Authentication is a constant-time hash comparison against the stored digest.
"""

from __future__ import annotations

import hashlib
import secrets
from datetime import UTC, datetime

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.api_token import ApiToken
from app.models.user import User

TOKEN_PREFIX = "sntl_"
# Scopes recognized by the platform. ``read`` permits safe methods; ``write``
# is required for any state-changing request made with the token.
VALID_SCOPES: tuple[str, ...] = ("read", "write")
# Refresh ``last_used_at`` at most this often to avoid write amplification on
# read-heavy token traffic.
_LAST_USED_THROTTLE_SECONDS = 60


def hash_token(full_token: str) -> str:
    """Return the SHA-256 hex digest used as the stored credential."""
    return hashlib.sha256(full_token.encode("utf-8")).hexdigest()


def looks_like_api_token(token: str) -> bool:
    return token.startswith(TOKEN_PREFIX)


def generate_token() -> tuple[str, str, str]:
    """Mint a new secret.

    Returns ``(full_token, token_prefix, token_hash)``. ``full_token`` is the
    only copy of the plaintext — surface it to the user once, then discard.
    """
    random_part = secrets.token_urlsafe(32)
    full_token = f"{TOKEN_PREFIX}{random_part}"
    # Prefix shown in the UI: scheme + first 8 chars of the random part.
    token_prefix = f"{TOKEN_PREFIX}{random_part[:8]}"
    return full_token, token_prefix, hash_token(full_token)


def normalize_scopes(scopes: list[str] | None) -> list[str]:
    """Keep only recognized scopes, deduped and ordered; default to read-only."""
    requested = {s.strip().lower() for s in (scopes or []) if s and s.strip()}
    cleaned = [s for s in VALID_SCOPES if s in requested]
    return cleaned or ["read"]


def authenticate_api_token(db: Session, full_token: str) -> tuple[User, list[str]] | None:
    """Resolve ``(owning_user, scopes)`` for a presented token, or ``None``.

    Touches ``last_used_at`` (throttled) so operators can spot stale/abandoned
    tokens. Returns ``None`` for unknown, revoked, expired, or inactive-owner
    tokens (fail-closed).
    """
    if not looks_like_api_token(full_token):
        return None
    digest = hash_token(full_token)
    token = db.execute(select(ApiToken).where(ApiToken.token_hash == digest)).scalar_one_or_none()
    if token is None or not token.is_active:
        return None
    user = db.get(User, token.user_id)
    if user is None or not user.is_active:
        return None

    now = datetime.now(UTC)
    last = token.last_used_at
    if last is not None and last.tzinfo is None:
        last = last.replace(tzinfo=UTC)
    if last is None or (now - last).total_seconds() >= _LAST_USED_THROTTLE_SECONDS:
        token.last_used_at = now
        db.commit()

    return user, list(token.scopes or [])
