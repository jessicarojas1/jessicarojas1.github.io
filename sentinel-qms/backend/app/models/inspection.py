"""Inspection & First Article (AS9102): Inspection, FaiReport, characteristics."""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class InspectionType(str, enum.Enum):
    RECEIVING = "receiving"
    IN_PROCESS = "in_process"
    FINAL = "final"
    FIRST_ARTICLE = "first_article"
    SOURCE = "source"


class InspectionResult(str, enum.Enum):
    PENDING = "pending"
    ACCEPT = "accept"
    REJECT = "reject"
    ACCEPT_WITH_DEVIATION = "accept_with_deviation"


class FaiType(str, enum.Enum):
    FULL = "full"
    PARTIAL = "partial"
    DELTA = "delta"


class Inspection(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "inspections"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    inspection_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    inspection_type: Mapped[InspectionType] = mapped_column(
        Enum(InspectionType, name="inspection_type"), nullable=False
    )
    result: Mapped[InspectionResult] = mapped_column(
        Enum(InspectionResult, name="inspection_result"),
        default=InspectionResult.PENDING,
        nullable=False,
    )
    part_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    lot_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    quantity_inspected: Mapped[int | None] = mapped_column(Integer, nullable=True)
    quantity_accepted: Mapped[int | None] = mapped_column(Integer, nullable=True)
    quantity_rejected: Mapped[int | None] = mapped_column(Integer, nullable=True)
    inspector_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    work_order: Mapped[str | None] = mapped_column(String(128), nullable=True)
    inspection_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    nonconformance_id: Mapped[int | None] = mapped_column(
        ForeignKey("nonconformances.id"), nullable=True
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    fai_report: Mapped[FaiReport | None] = relationship(
        "FaiReport", back_populates="inspection", uselist=False, cascade="all, delete-orphan"
    )


class FaiReport(Base, TimestampMixin):
    """First Article Inspection Report (AS9102 Forms 1/2/3 summary)."""

    __tablename__ = "fai_reports"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fai_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    inspection_id: Mapped[int | None] = mapped_column(
        ForeignKey("inspections.id", ondelete="SET NULL"), nullable=True, index=True
    )
    part_number: Mapped[str] = mapped_column(String(128), nullable=False)
    part_name: Mapped[str | None] = mapped_column(String(255), nullable=True)
    part_revision: Mapped[str | None] = mapped_column(String(32), nullable=True)
    drawing_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    fai_type: Mapped[FaiType] = mapped_column(
        Enum(FaiType, name="fai_type"), default=FaiType.FULL, nullable=False
    )
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    baseline_part_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    disposition: Mapped[str | None] = mapped_column(
        String(32), nullable=True
    )  # complete/incomplete
    prepared_by: Mapped[str | None] = mapped_column(String(255), nullable=True)
    fai_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    inspection: Mapped[Inspection | None] = relationship("Inspection", back_populates="fai_report")
    characteristics: Mapped[list[FaiCharacteristic]] = relationship(
        "FaiCharacteristic",
        back_populates="fai_report",
        cascade="all, delete-orphan",
        order_by="FaiCharacteristic.balloon_number",
    )


class FaiCharacteristic(Base):
    """AS9102 Form 3 characteristic / balloon record."""

    __tablename__ = "fai_characteristics"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fai_report_id: Mapped[int] = mapped_column(
        ForeignKey("fai_reports.id", ondelete="CASCADE"), nullable=False, index=True
    )
    balloon_number: Mapped[str] = mapped_column(String(16), nullable=False)
    characteristic: Mapped[str] = mapped_column(String(255), nullable=False)
    requirement: Mapped[str | None] = mapped_column(String(255), nullable=True)
    nominal: Mapped[float | None] = mapped_column(Numeric(14, 5), nullable=True)
    tol_minus: Mapped[float | None] = mapped_column(Numeric(14, 5), nullable=True)
    tol_plus: Mapped[float | None] = mapped_column(Numeric(14, 5), nullable=True)
    measured_value: Mapped[float | None] = mapped_column(Numeric(14, 5), nullable=True)
    measurement_method: Mapped[str | None] = mapped_column(String(128), nullable=True)
    result: Mapped[str | None] = mapped_column(String(16), nullable=True)  # pass/fail
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    fai_report: Mapped[FaiReport] = relationship("FaiReport", back_populates="characteristics")
