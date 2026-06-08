"""Per-user saved list views (filter / sort / search presets)."""

from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class SavedView(Base, TimestampMixin):
    __tablename__ = "saved_views"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    user_id: Mapped[int] = mapped_column(
        ForeignKey("users.id", ondelete="CASCADE"), nullable=False, index=True
    )
    # Which list this view belongs to, e.g. "nonconformances".
    page_key: Mapped[str] = mapped_column(String(64), nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    # Serialized list params (search / sort / order / filters) as JSON text.
    params: Mapped[str] = mapped_column(Text, nullable=False, default="{}")
