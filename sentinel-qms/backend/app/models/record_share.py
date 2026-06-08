"""Record shares — grant a user a read-only pointer to a specific record.

A share is a *reference*, not an access grant: the recipient sees it in their
"Shared with Me" inbox and follows it in-app (still authenticated). It never
exposes data publicly.
"""

from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class RecordShare(Base, TimestampMixin):
    __tablename__ = "record_shares"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    entity_type: Mapped[str] = mapped_column(String(64), nullable=False)
    entity_id: Mapped[str] = mapped_column(String(64), nullable=False)
    # Human label captured at share time (e.g. "NCR-2026-0007 — Bad weld").
    label: Mapped[str] = mapped_column(String(512), nullable=False)
    shared_with_user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    shared_by_user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    note: Mapped[str | None] = mapped_column(Text, nullable=True)
