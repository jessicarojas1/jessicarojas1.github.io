"""Calibration: Equipment (gages) and CalibrationRecord."""
from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class EquipmentStatus(str, enum.Enum):
    ACTIVE = "active"
    OUT_OF_SERVICE = "out_of_service"
    LOST = "lost"
    RETIRED = "retired"


class CalibrationResult(str, enum.Enum):
    PASS = "pass"
    PASS_WITH_ADJUSTMENT = "pass_with_adjustment"
    FAIL = "fail"
    LIMITED = "limited"


class Equipment(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "equipment"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    asset_tag: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    equipment_type: Mapped[str | None] = mapped_column(String(128), nullable=True)
    manufacturer: Mapped[str | None] = mapped_column(String(128), nullable=True)
    model: Mapped[str | None] = mapped_column(String(128), nullable=True)
    serial_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    location: Mapped[str | None] = mapped_column(String(128), nullable=True)
    status: Mapped[EquipmentStatus] = mapped_column(
        Enum(EquipmentStatus, name="equipment_status"),
        default=EquipmentStatus.ACTIVE,
        nullable=False,
    )
    calibration_interval_days: Mapped[int] = mapped_column(
        Integer, default=365, nullable=False
    )
    last_calibration_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    next_due_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    custodian_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)

    records: Mapped[list[CalibrationRecord]] = relationship(
        "CalibrationRecord",
        back_populates="equipment",
        cascade="all, delete-orphan",
        order_by="CalibrationRecord.calibration_date",
    )


class CalibrationRecord(Base, TimestampMixin):
    __tablename__ = "calibration_records"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    equipment_id: Mapped[int] = mapped_column(
        ForeignKey("equipment.id", ondelete="CASCADE"), nullable=False, index=True
    )
    calibration_date: Mapped[date] = mapped_column(Date, nullable=False)
    due_date: Mapped[date] = mapped_column(Date, nullable=False)
    result: Mapped[CalibrationResult] = mapped_column(
        Enum(CalibrationResult, name="calibration_result"), nullable=False
    )
    certificate_number: Mapped[str | None] = mapped_column(String(128), nullable=True)
    performed_by: Mapped[str | None] = mapped_column(String(255), nullable=True)
    calibration_vendor: Mapped[str | None] = mapped_column(String(255), nullable=True)
    standard_used: Mapped[str | None] = mapped_column(String(255), nullable=True)
    as_found: Mapped[str | None] = mapped_column(Text, nullable=True)
    as_left: Mapped[str | None] = mapped_column(Text, nullable=True)
    uncertainty: Mapped[float | None] = mapped_column(Numeric(12, 6), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    attachment_id: Mapped[int | None] = mapped_column(ForeignKey("attachments.id"), nullable=True)

    equipment: Mapped[Equipment] = relationship("Equipment", back_populates="records")
