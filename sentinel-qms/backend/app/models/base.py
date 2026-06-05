"""Shared SQLAlchemy mixins: timestamps, authorship, soft-delete."""
from __future__ import annotations

from datetime import datetime

from sqlalchemy import Boolean, DateTime, Integer, func
from sqlalchemy.orm import Mapped, mapped_column


class TimestampMixin:
    """created_at / updated_at plus created_by / updated_by authorship columns."""

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )
    created_by: Mapped[int | None] = mapped_column(Integer, nullable=True)
    updated_by: Mapped[int | None] = mapped_column(Integer, nullable=True)


class SoftDeleteMixin:
    """Soft-delete flag for controlled records (documents, NCR, CAPA, audits)."""

    is_deleted: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    deleted_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    deleted_by: Mapped[int | None] = mapped_column(Integer, nullable=True)

    def soft_delete(self, actor_id: int | None = None) -> None:
        from datetime import timezone as _tz

        self.is_deleted = True
        self.deleted_at = datetime.now(_tz.utc)
        self.deleted_by = actor_id
