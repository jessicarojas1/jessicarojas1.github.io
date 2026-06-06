"""Organization-wide settings & branding — a single-row (singleton) table."""
from __future__ import annotations

from sqlalchemy import Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class OrgSettings(Base, TimestampMixin):
    """Singleton organization settings row (intended id=1).

    Holds branding (name/logo/primary color), contact, and default cadence
    settings shared across the QMS. Exactly one row is expected; the API
    creates it on first read when missing.
    """

    __tablename__ = "org_settings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_name: Mapped[str] = mapped_column(
        String(255), default="Sentinel QMS", nullable=False
    )
    # Text (not a bounded VARCHAR) so an uploaded logo stored as a data: URL fits.
    logo_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    primary_color: Mapped[str | None] = mapped_column(String(32), nullable=True)
    support_email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    default_review_cycle_days: Mapped[int] = mapped_column(
        Integer, default=365, nullable=False
    )
    calibration_default_interval_days: Mapped[int] = mapped_column(
        Integer, default=365, nullable=False
    )
    timezone: Mapped[str] = mapped_column(String(64), default="UTC", nullable=False)
