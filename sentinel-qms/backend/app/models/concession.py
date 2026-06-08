"""Concession / Deviation / Waiver permits.

A documented, time-/quantity-bounded permission to depart from a requirement,
authorized **before or during** production (distinct from an NCR, which records
a departure after the fact). Covers AS9100 8.7 concessions and pre-production
deviation/waiver permits.
"""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Boolean, Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ConcessionType(str, enum.Enum):
    DEVIATION = "deviation"  # authorized departure before manufacture
    WAIVER = "waiver"  # accept already-produced product as-is
    CONCESSION = "concession"  # customer-granted permission to supply nonconforming


class ConcessionStatus(str, enum.Enum):
    DRAFT = "draft"
    SUBMITTED = "submitted"
    UNDER_REVIEW = "under_review"
    APPROVED = "approved"
    REJECTED = "rejected"
    EXPIRED = "expired"
    CLOSED = "closed"


class Concession(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "concessions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    concession_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    concession_type: Mapped[ConcessionType] = mapped_column(
        Enum(ConcessionType, name="concession_type"),
        default=ConcessionType.DEVIATION,
        nullable=False,
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    part_number: Mapped[str | None] = mapped_column(String(128), nullable=True, index=True)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    justification: Mapped[str | None] = mapped_column(Text, nullable=True)
    quantity: Mapped[int | None] = mapped_column(Integer, nullable=True)
    status: Mapped[ConcessionStatus] = mapped_column(
        Enum(ConcessionStatus, name="concession_status"),
        default=ConcessionStatus.DRAFT,
        nullable=False,
    )
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    customer_approval_required: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    customer_approved: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    expiry_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
