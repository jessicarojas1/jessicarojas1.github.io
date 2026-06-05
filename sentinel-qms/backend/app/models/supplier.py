"""Supplier quality: Supplier, SupplierScar, ApprovedSupplierListEntry, SupplierRating."""
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


class SupplierStatus(str, enum.Enum):
    PROSPECTIVE = "prospective"
    APPROVED = "approved"
    CONDITIONAL = "conditional"
    PROBATION = "probation"
    DISQUALIFIED = "disqualified"


class ScarStatus(str, enum.Enum):
    ISSUED = "issued"
    ACKNOWLEDGED = "acknowledged"
    RESPONSE_RECEIVED = "response_received"
    VERIFIED = "verified"
    CLOSED = "closed"


class Supplier(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "suppliers"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    supplier_code: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    status: Mapped[SupplierStatus] = mapped_column(
        Enum(SupplierStatus, name="supplier_status"),
        default=SupplierStatus.PROSPECTIVE,
        nullable=False,
    )
    cage_code: Mapped[str | None] = mapped_column(String(16), nullable=True)
    duns_number: Mapped[str | None] = mapped_column(String(16), nullable=True)
    certification: Mapped[str | None] = mapped_column(String(128), nullable=True)  # AS9100, ISO9001
    cert_expiry: Mapped[date | None] = mapped_column(Date, nullable=True)
    contact_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    contact_email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    country: Mapped[str | None] = mapped_column(String(64), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    scars: Mapped[list[SupplierScar]] = relationship(
        "SupplierScar", back_populates="supplier", cascade="all, delete-orphan"
    )
    ratings: Mapped[list[SupplierRating]] = relationship(
        "SupplierRating", back_populates="supplier", cascade="all, delete-orphan"
    )
    asl_entries: Mapped[list[ApprovedSupplierListEntry]] = relationship(
        "ApprovedSupplierListEntry", back_populates="supplier", cascade="all, delete-orphan"
    )


class SupplierScar(Base, TimestampMixin):
    """Supplier Corrective Action Request."""

    __tablename__ = "supplier_scars"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    scar_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    supplier_id: Mapped[int] = mapped_column(
        ForeignKey("suppliers.id", ondelete="CASCADE"), nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[ScarStatus] = mapped_column(
        Enum(ScarStatus, name="scar_status"), default=ScarStatus.ISSUED, nullable=False
    )
    nonconformance_id: Mapped[int | None] = mapped_column(
        ForeignKey("nonconformances.id"), nullable=True
    )
    issued_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    response_due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    supplier_response: Mapped[str | None] = mapped_column(Text, nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    supplier: Mapped[Supplier] = relationship("Supplier", back_populates="scars")


class ApprovedSupplierListEntry(Base, TimestampMixin):
    __tablename__ = "approved_supplier_list"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    supplier_id: Mapped[int] = mapped_column(
        ForeignKey("suppliers.id", ondelete="CASCADE"), nullable=False, index=True
    )
    commodity: Mapped[str] = mapped_column(String(255), nullable=False)
    process_scope: Mapped[str | None] = mapped_column(Text, nullable=True)
    approved_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    expiry_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    is_active: Mapped[bool] = mapped_column(default=True, nullable=False)

    supplier: Mapped[Supplier] = relationship("Supplier", back_populates="asl_entries")


class SupplierRating(Base, TimestampMixin):
    """Periodic supplier scorecard (quality + delivery)."""

    __tablename__ = "supplier_ratings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    supplier_id: Mapped[int] = mapped_column(
        ForeignKey("suppliers.id", ondelete="CASCADE"), nullable=False, index=True
    )
    period: Mapped[str] = mapped_column(String(16), nullable=False)  # e.g. 2026-Q1
    quality_score: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    on_time_delivery: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    ppm_defects: Mapped[int | None] = mapped_column(Integer, nullable=True)
    composite_score: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    grade: Mapped[str | None] = mapped_column(String(4), nullable=True)  # A/B/C/D
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    supplier: Mapped[Supplier] = relationship("Supplier", back_populates="ratings")
