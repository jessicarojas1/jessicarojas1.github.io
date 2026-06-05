"""Customer complaint / RMA, linked to NCR and CAPA."""
from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ComplaintStatus(str, enum.Enum):
    RECEIVED = "received"
    UNDER_INVESTIGATION = "under_investigation"
    AWAITING_CUSTOMER = "awaiting_customer"
    RESOLVED = "resolved"
    CLOSED = "closed"


class ComplaintSeverity(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class Complaint(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "complaints"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    complaint_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[ComplaintStatus] = mapped_column(
        Enum(ComplaintStatus, name="complaint_status"),
        default=ComplaintStatus.RECEIVED,
        nullable=False,
        index=True,
    )
    severity: Mapped[ComplaintSeverity] = mapped_column(
        Enum(ComplaintSeverity, name="complaint_severity"),
        default=ComplaintSeverity.MEDIUM,
        nullable=False,
    )
    customer_name: Mapped[str] = mapped_column(String(255), nullable=False)
    customer_contact: Mapped[str | None] = mapped_column(String(255), nullable=True)
    part_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    serial_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    rma_number: Mapped[str | None] = mapped_column(String(64), nullable=True)
    is_rma: Mapped[bool] = mapped_column(default=False, nullable=False)
    received_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    response_due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    resolution: Mapped[str | None] = mapped_column(Text, nullable=True)
    assigned_to: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    nonconformance_id: Mapped[int | None] = mapped_column(
        ForeignKey("nonconformances.id"), nullable=True
    )
    capa_id: Mapped[int | None] = mapped_column(ForeignKey("capas.id"), nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
