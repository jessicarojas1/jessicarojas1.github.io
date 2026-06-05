"""Nonconformance (NCR) and MRB disposition models."""
from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import (
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class NcSeverity(str, enum.Enum):
    MINOR = "minor"
    MAJOR = "major"
    CRITICAL = "critical"


class NcStatus(str, enum.Enum):
    OPEN = "open"
    UNDER_REVIEW = "under_review"
    DISPOSITIONED = "dispositioned"
    CLOSED = "closed"
    VOID = "void"


class DispositionType(str, enum.Enum):
    USE_AS_IS = "use_as_is"
    REWORK = "rework"
    REPAIR = "repair"
    SCRAP = "scrap"
    RETURN = "return"


class Nonconformance(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "nonconformances"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    ncr_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    severity: Mapped[NcSeverity] = mapped_column(
        Enum(NcSeverity, name="nc_severity"), default=NcSeverity.MINOR, nullable=False
    )
    status: Mapped[NcStatus] = mapped_column(
        Enum(NcStatus, name="nc_status"), default=NcStatus.OPEN, nullable=False, index=True
    )

    part_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    lot_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    serial_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    quantity_affected: Mapped[int | None] = mapped_column(Integer, nullable=True)
    estimated_cost: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)

    source: Mapped[str | None] = mapped_column(String(64), nullable=True)  # receiving/in-process/customer
    detected_at: Mapped[date | None] = mapped_column(Date, nullable=True)
    work_order: Mapped[str | None] = mapped_column(String(128), nullable=True)

    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    assigned_to: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    capa_id: Mapped[int | None] = mapped_column(ForeignKey("capas.id"), nullable=True)

    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    dispositions: Mapped[list[NonconformanceDisposition]] = relationship(
        "NonconformanceDisposition",
        back_populates="nonconformance",
        cascade="all, delete-orphan",
        order_by="NonconformanceDisposition.id",
    )


class NonconformanceDisposition(Base, TimestampMixin):
    """Material Review Board (MRB) disposition decision."""

    __tablename__ = "nonconformance_dispositions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    nonconformance_id: Mapped[int] = mapped_column(
        ForeignKey("nonconformances.id", ondelete="CASCADE"), nullable=False, index=True
    )
    disposition_type: Mapped[DispositionType] = mapped_column(
        Enum(DispositionType, name="disposition_type"), nullable=False
    )
    justification: Mapped[str] = mapped_column(Text, nullable=False)
    mrb_members: Mapped[str | None] = mapped_column(Text, nullable=True)
    customer_approval_required: Mapped[bool] = mapped_column(default=False, nullable=False)
    customer_approved: Mapped[bool] = mapped_column(default=False, nullable=False)
    decided_by: Mapped[int] = mapped_column(ForeignKey("users.id"), nullable=False)
    signature_id: Mapped[int | None] = mapped_column(
        ForeignKey("electronic_signatures.id"), nullable=True
    )

    nonconformance: Mapped[Nonconformance] = relationship(
        "Nonconformance", back_populates="dispositions"
    )
