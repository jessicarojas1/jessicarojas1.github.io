"""Foreign Object Debris (FOD) prevention program (AS9146).

A :class:`FodZone` registry defines FOD-control areas; :class:`FodEvent` logs
each detected foreign object with investigation, severity and disposition.
"""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Boolean, Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class FodRisk(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"


class FodSeverity(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class FodStatus(str, enum.Enum):
    OPEN = "open"
    INVESTIGATING = "investigating"
    CONTAINED = "contained"
    CLOSED = "closed"


class FodZone(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "fod_zones"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    risk_level: Mapped[FodRisk] = mapped_column(
        Enum(FodRisk, name="fod_risk"), default=FodRisk.MEDIUM, nullable=False
    )
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)


class FodEvent(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "fod_events"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    event_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    zone_id: Mapped[int | None] = mapped_column(ForeignKey("fod_zones.id"), nullable=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    object_type: Mapped[str | None] = mapped_column(String(255), nullable=True)
    location: Mapped[str | None] = mapped_column(String(255), nullable=True)
    severity: Mapped[FodSeverity] = mapped_column(
        Enum(FodSeverity, name="fod_severity"), default=FodSeverity.MEDIUM, nullable=False
    )
    status: Mapped[FodStatus] = mapped_column(
        Enum(FodStatus, name="fod_status"), default=FodStatus.OPEN, nullable=False
    )
    discovered_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    root_cause: Mapped[str | None] = mapped_column(Text, nullable=True)
    corrective_action: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Set when an NCR has been raised from this FOD event.
    ncr_id: Mapped[int | None] = mapped_column(ForeignKey("nonconformances.id"), nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
