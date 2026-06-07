"""Equipment and calibration record schemas."""

from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel, Field

from app.models.calibration import CalibrationResult, EquipmentStatus
from app.schemas.common import ORMModel


class EquipmentBase(BaseModel):
    name: str = Field(..., min_length=1, max_length=255)
    equipment_type: str | None = Field(default=None, max_length=128)
    manufacturer: str | None = Field(default=None, max_length=128)
    model: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    location: str | None = Field(default=None, max_length=128)
    status: EquipmentStatus = EquipmentStatus.ACTIVE
    calibration_interval_days: int = Field(default=365, ge=1, le=3650)
    custodian_id: int | None = None


class EquipmentCreate(EquipmentBase):
    last_calibration_date: date | None = None


class EquipmentUpdate(BaseModel):
    name: str | None = Field(default=None, max_length=255)
    equipment_type: str | None = Field(default=None, max_length=128)
    manufacturer: str | None = Field(default=None, max_length=128)
    model: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    location: str | None = Field(default=None, max_length=128)
    status: EquipmentStatus | None = None
    calibration_interval_days: int | None = Field(default=None, ge=1, le=3650)
    custodian_id: int | None = None


class CalibrationRecordCreate(BaseModel):
    calibration_date: date
    result: CalibrationResult
    certificate_number: str | None = Field(default=None, max_length=128)
    performed_by: str | None = Field(default=None, max_length=255)
    calibration_vendor: str | None = Field(default=None, max_length=255)
    standard_used: str | None = Field(default=None, max_length=255)
    as_found: str | None = None
    as_left: str | None = None
    uncertainty: Decimal | None = None
    notes: str | None = None
    # Optional explicit override of due date; otherwise computed from interval.
    due_date: date | None = None


class CalibrationRecordRead(ORMModel):
    id: int
    equipment_id: int
    calibration_date: date
    due_date: date
    result: CalibrationResult
    certificate_number: str | None
    performed_by: str | None
    calibration_vendor: str | None
    standard_used: str | None
    as_found: str | None
    as_left: str | None
    uncertainty: Decimal | None
    notes: str | None
    created_at: datetime | None = None


class EquipmentRead(ORMModel):
    id: int
    asset_tag: str
    name: str
    equipment_type: str | None
    manufacturer: str | None
    model: str | None
    serial_number: str | None
    location: str | None
    status: EquipmentStatus
    calibration_interval_days: int
    last_calibration_date: date | None
    next_due_date: date | None
    custodian_id: int | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    records: list[CalibrationRecordRead] = []


class EquipmentList(ORMModel):
    id: int
    asset_tag: str
    name: str
    status: EquipmentStatus
    location: str | None
    next_due_date: date | None
    created_at: datetime | None = None
