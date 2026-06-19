"""Outbound webhooks — subscribe external systems to QMS lifecycle events.

A :class:`Webhook` is an admin-registered HTTPS endpoint plus a signing secret.
When a subscribed event occurs, a :class:`WebhookDelivery` row is created
(atomically, in the same transaction as the change) and later dispatched with an
HMAC-SHA256 signature, with bounded exponential-backoff retries. Only the SHA-256
hash of nothing is stored here — the secret must be retained to sign each
delivery, so it is stored and exposed only to admins.
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    BigInteger,
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column
from sqlalchemy.types import JSON

from app.core.database import Base
from app.models.base import TimestampMixin

# JSONB on Postgres, plain JSON elsewhere (SQLite in tests).
_JSON = JSON().with_variant(JSONB, "postgresql")


class Webhook(Base, TimestampMixin):
    __tablename__ = "webhooks"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    url: Mapped[str] = mapped_column(String(1024), nullable=False)
    # Shared secret used to compute the HMAC-SHA256 delivery signature.
    secret: Mapped[str] = mapped_column(String(128), nullable=False)
    # Subscribed event/action names; ["*"] (or empty) means "all events".
    event_types: Mapped[list] = mapped_column(_JSON, nullable=False, default=list)
    description: Mapped[str | None] = mapped_column(String(512), nullable=True)
    active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    created_by: Mapped[int | None] = mapped_column(Integer, nullable=True)

    def subscribes_to(self, event_type: str) -> bool:
        evs = self.event_types or []
        return "*" in evs or event_type in evs


class WebhookDelivery(Base):
    """A single attempt-tracked delivery of one event to one webhook."""

    __tablename__ = "webhook_deliveries"

    # SQLite (tests) only auto-increments INTEGER PRIMARY KEY, not BIGINT.
    id: Mapped[int] = mapped_column(BigInteger().with_variant(Integer, "sqlite"), primary_key=True)
    webhook_id: Mapped[int] = mapped_column(
        ForeignKey("webhooks.id", ondelete="CASCADE"), nullable=False, index=True
    )
    event_type: Mapped[str] = mapped_column(String(128), nullable=False)
    payload: Mapped[dict] = mapped_column(_JSON, nullable=False)
    # pending | success | failed | dead (exhausted retries)
    status: Mapped[str] = mapped_column(String(16), default="pending", nullable=False, index=True)
    attempts: Mapped[int] = mapped_column(Integer, default=0, nullable=False)
    last_status_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    last_error: Mapped[str | None] = mapped_column(Text, nullable=True)
    duration_ms: Mapped[int | None] = mapped_column(Integer, nullable=True)
    # When the next attempt becomes due (set on enqueue and after each failure).
    next_attempt_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True, index=True
    )
    delivered_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
