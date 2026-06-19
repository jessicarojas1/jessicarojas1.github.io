"""Refresh-token issuance, rotation, reuse-detection, and revocation."""

from __future__ import annotations

import logging
from datetime import UTC, datetime

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from app.core.exceptions import AuthenticationError
from app.core.security import REFRESH_TOKEN_TYPE, decode_token, new_refresh_token
from app.models.refresh_token import RefreshToken
from app.models.user import User

logger = logging.getLogger("app.auth")


def issue(db: Session, user: User, *, ip: str | None = None, user_agent: str | None = None) -> str:
    """Mint and persist a new refresh token for ``user``; return the token string."""
    token, jti, expires_at = new_refresh_token(str(user.id))
    db.add(
        RefreshToken(
            user_id=user.id,
            jti=jti,
            expires_at=expires_at,
            ip_address=ip,
            user_agent=(user_agent or None) and user_agent[:256],
        )
    )
    db.flush()
    return token


def revoke_all(db: Session, user_id: int) -> int:
    """Revoke every active refresh token for a user. Returns rows affected."""
    now = datetime.now(UTC)
    result = db.execute(
        update(RefreshToken)
        .where(RefreshToken.user_id == user_id, RefreshToken.revoked_at.is_(None))
        .values(revoked_at=now)
    )
    return result.rowcount or 0


def rotate(
    db: Session,
    presented_token: str,
    *,
    ip: str | None = None,
    user_agent: str | None = None,
) -> tuple[User, str]:
    """Validate + rotate a refresh token. Returns ``(user, new_refresh_token)``.

    Enforces signature/expiry/type (via :func:`decode_token`) and the server-side
    record state. Detects token reuse: presenting an already-revoked token that
    was rotated away revokes the user's entire active set (fail-closed).
    """
    payload = decode_token(presented_token, expected_type=REFRESH_TOKEN_TYPE)
    jti = payload.get("jti")
    try:
        user_id = int(payload["sub"])
    except (KeyError, ValueError) as exc:
        raise AuthenticationError("Malformed refresh token.") from exc
    if not jti:
        raise AuthenticationError("Refresh token is missing its identifier.")

    record = db.execute(select(RefreshToken).where(RefreshToken.jti == jti)).scalar_one_or_none()
    if record is None:
        # Unknown token (never issued, or issued before server-side tracking).
        raise AuthenticationError("Refresh token is no longer valid. Please sign in again.")

    if record.revoked_at is not None:
        # Reuse of a rotated/revoked token → likely theft. Burn the whole set.
        if record.replaced_by_jti is not None:
            revoke_all(db, record.user_id)
            db.flush()
            logger.warning("refresh_token_reuse_detected user_id=%s", record.user_id)
        raise AuthenticationError("Refresh token is no longer valid. Please sign in again.")

    exp = record.expires_at
    if exp.tzinfo is None:
        exp = exp.replace(tzinfo=UTC)
    if exp <= datetime.now(UTC):
        raise AuthenticationError("Refresh token has expired. Please sign in again.")

    user = db.get(User, user_id)
    if user is None or not user.is_active:
        raise AuthenticationError("User not found or inactive.")

    # Rotate: revoke the presented token and issue a fresh one chained to it.
    new_token, new_jti, new_expires = new_refresh_token(str(user.id))
    record.revoked_at = datetime.now(UTC)
    record.replaced_by_jti = new_jti
    db.add(
        RefreshToken(
            user_id=user.id,
            jti=new_jti,
            expires_at=new_expires,
            ip_address=ip,
            user_agent=(user_agent or None) and user_agent[:256],
        )
    )
    db.flush()
    return user, new_token
