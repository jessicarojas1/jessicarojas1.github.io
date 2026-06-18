"""Personal Access Tokens — scoped API keys for programmatic / service access.

A token *acts as* its owning user: every normal RBAC check (page levels, granular
permissions, per-record access) still applies on top. The token additionally
carries a coarse scope (``read`` and/or ``write``) so a read-only integration
cannot mutate data even if its owner could. Only the SHA-256 hash of the secret
is stored — the plaintext is shown exactly once at creation time.
"""

from __future__ import annotations

from datetime import UTC, datetime

from sqlalchemy import DateTime, ForeignKey, Integer, String
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column
from sqlalchemy.types import JSON

from app.core.database import Base
from app.models.base import TimestampMixin

# Use JSONB on Postgres, plain JSON elsewhere (SQLite in tests).
_JSON = JSON().with_variant(JSONB, "postgresql")


def token_is_active(revoked_at: datetime | None, expires_at: datetime | None) -> bool:
    """Single source of truth for token validity (shared by model + schema).

    A token is active unless it has been revoked or has passed its expiry.
    Naive datetimes (e.g. from SQLite) are treated as UTC.
    """
    if revoked_at is not None:
        return False
    if expires_at is not None:
        exp = expires_at if expires_at.tzinfo is not None else expires_at.replace(tzinfo=UTC)
        if exp <= datetime.now(UTC):
            return False
    return True


class ApiToken(Base, TimestampMixin):
    __tablename__ = "api_tokens"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    # Human label, e.g. "CI export job".
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    # Non-secret identifying prefix shown in the UI, e.g. "sntl_ab12cd34".
    token_prefix: Mapped[str] = mapped_column(String(32), nullable=False, index=True)
    # SHA-256 hex digest of the full secret — the secret itself is never stored.
    token_hash: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    # Coarse scopes, e.g. ["read", "write"].
    scopes: Mapped[list] = mapped_column(_JSON, nullable=False, default=list)
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    revoked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_by: Mapped[int | None] = mapped_column(Integer, nullable=True)

    @property
    def is_active(self) -> bool:
        return token_is_active(self.revoked_at, self.expires_at)
