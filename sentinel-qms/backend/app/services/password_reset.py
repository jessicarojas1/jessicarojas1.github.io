"""Self-service password reset: token creation, email, and consumption.

Tokens are random, stored only as a SHA-256 hash, single-use, and short-lived.
Requesting a reset never reveals whether an account exists (no enumeration).
Consuming a reset also revokes the user's refresh tokens (sign out everywhere).
"""

from __future__ import annotations

import hashlib
import logging
import secrets
from datetime import UTC, datetime, timedelta

from sqlalchemy import select, update
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.exceptions import ValidationAppError
from app.core.security import hash_password
from app.models.password_reset import PasswordResetToken
from app.models.user import User
from app.services import delivery, refresh_tokens

logger = logging.getLogger("app.auth")


def _hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def create_reset(db: Session, user: User) -> str:
    """Invalidate any prior unused tokens and mint a new one; return plaintext."""
    db.execute(
        update(PasswordResetToken)
        .where(
            PasswordResetToken.user_id == user.id,
            PasswordResetToken.used_at.is_(None),
        )
        .values(used_at=datetime.now(UTC))
    )
    token = secrets.token_urlsafe(32)
    db.add(
        PasswordResetToken(
            user_id=user.id,
            token_hash=_hash(token),
            expires_at=datetime.now(UTC) + timedelta(minutes=settings.PASSWORD_RESET_TTL_MINUTES),
        )
    )
    db.flush()
    return token


def request_reset(db: Session, email: str) -> None:
    """Best-effort: if the email maps to a local (non-SSO) active account, email
    a reset link. Always returns ``None`` so callers can respond generically."""
    user = db.execute(select(User).where(User.email == email.lower())).scalar_one_or_none()
    if user is None or not user.is_active or not user.hashed_password:
        return
    token = create_reset(db, user)
    base = (settings.APP_BASE_URL or "").rstrip("/")
    link = f"{base}/reset-password?token={token}" if base else None
    try:
        cfg = delivery.resolve_channels(db)
        if cfg.email_ready:
            delivery.send_email(
                cfg,
                user.email,
                "Reset your Sentinel QMS password",
                "A password reset was requested for your account. If this was you, "
                "use the link below within the next "
                f"{settings.PASSWORD_RESET_TTL_MINUTES} minutes. If not, ignore this email.",
                link,
            )
        else:
            logger.info("password reset requested for user_id=%s (no email channel)", user.id)
    except Exception:  # noqa: BLE001 — never reveal delivery state to the caller
        logger.warning("password_reset_email_failed", exc_info=True)


def consume(db: Session, token: str, new_password: str) -> User:
    """Validate a reset token and set the new password. Raises on invalid input."""
    if len(new_password) < 12:
        raise ValidationAppError("Password must be at least 12 characters.")
    record = db.execute(
        select(PasswordResetToken).where(PasswordResetToken.token_hash == _hash(token))
    ).scalar_one_or_none()
    now = datetime.now(UTC)
    if record is None or record.used_at is not None:
        raise ValidationAppError("This reset link is invalid or has already been used.")
    exp = record.expires_at if record.expires_at.tzinfo else record.expires_at.replace(tzinfo=UTC)
    if exp <= now:
        raise ValidationAppError("This reset link has expired. Please request a new one.")
    user = db.get(User, record.user_id)
    if user is None or not user.is_active:
        raise ValidationAppError("This reset link is invalid or has already been used.")

    user.hashed_password = hash_password(new_password)
    record.used_at = now
    # Reset means "I lost control of the old session" — burn refresh tokens.
    refresh_tokens.revoke_all(db, user.id)
    db.flush()
    return user
