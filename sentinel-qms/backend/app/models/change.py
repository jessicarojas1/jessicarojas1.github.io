"""Engineering change management: ChangeOrder (ECN/ECO workflow)."""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ChangeType(str, enum.Enum):
    ECN = "ecn"  # Engineering Change Notice
    ECO = "eco"  # Engineering Change Order
    DEVIATION = "deviation"
    WAIVER = "waiver"


class ChangeStatus(str, enum.Enum):
    DRAFT = "draft"
    SUBMITTED = "submitted"
    UNDER_REVIEW = "under_review"
    APPROVED = "approved"
    REJECTED = "rejected"
    IMPLEMENTED = "implemented"
    CLOSED = "closed"


class ChangePriority(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    EMERGENCY = "emergency"


class ChangeOrder(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "change_orders"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    change_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    change_type: Mapped[ChangeType] = mapped_column(
        Enum(ChangeType, name="change_type"), default=ChangeType.ECN, nullable=False
    )
    status: Mapped[ChangeStatus] = mapped_column(
        Enum(ChangeStatus, name="change_status"), default=ChangeStatus.DRAFT, nullable=False
    )
    priority: Mapped[ChangePriority] = mapped_column(
        Enum(ChangePriority, name="change_priority"),
        default=ChangePriority.MEDIUM,
        nullable=False,
    )
    description: Mapped[str] = mapped_column(Text, nullable=False)
    reason: Mapped[str | None] = mapped_column(Text, nullable=True)
    affected_items: Mapped[str | None] = mapped_column(Text, nullable=True)  # part / doc numbers
    impact_analysis: Mapped[str | None] = mapped_column(Text, nullable=True)
    requested_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    document_id: Mapped[int | None] = mapped_column(ForeignKey("documents.id"), nullable=True)
    target_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    approved_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    signature_id: Mapped[int | None] = mapped_column(
        ForeignKey("electronic_signatures.id"), nullable=True
    )
    implemented_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
