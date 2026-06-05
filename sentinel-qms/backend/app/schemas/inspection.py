"""Inspection and FAI (AS9102) schemas."""
from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel, Field

from app.models.inspection import (
    FaiType,
    InspectionResult,
    InspectionType,
)
from app.schemas.common import ORMModel


class InspectionBase(BaseModel):
    inspection_type: InspectionType
    part_number: str | None = Field(default=None, max_length=128)
    lot_number: str | None = Field(default=None, max_length=128)
    quantity_inspected: int | None = Field(default=None, ge=0)
    quantity_accepted: int | None = Field(default=None, ge=0)
    quantity_rejected: int | None = Field(default=None, ge=0)
    supplier_id: int | None = None
    work_order: str | None = Field(default=None, max_length=128)
    inspection_date: date | None = None
    notes: str | None = None


class InspectionCreate(InspectionBase):
    pass


class InspectionUpdate(BaseModel):
    inspection_type: InspectionType | None = None
    result: InspectionResult | None = None
    part_number: str | None = Field(default=None, max_length=128)
    lot_number: str | None = Field(default=None, max_length=128)
    quantity_inspected: int | None = Field(default=None, ge=0)
    quantity_accepted: int | None = Field(default=None, ge=0)
    quantity_rejected: int | None = Field(default=None, ge=0)
    supplier_id: int | None = None
    work_order: str | None = Field(default=None, max_length=128)
    inspection_date: date | None = None
    notes: str | None = None


class FaiCharacteristicCreate(BaseModel):
    balloon_number: str = Field(..., min_length=1, max_length=16)
    characteristic: str = Field(..., min_length=1, max_length=255)
    requirement: str | None = Field(default=None, max_length=255)
    nominal: Decimal | None = None
    tol_minus: Decimal | None = None
    tol_plus: Decimal | None = None
    measured_value: Decimal | None = None
    measurement_method: str | None = Field(default=None, max_length=128)
    result: str | None = Field(default=None, max_length=16)
    notes: str | None = None


class FaiCharacteristicRead(ORMModel):
    id: int
    fai_report_id: int
    balloon_number: str
    characteristic: str
    requirement: str | None
    nominal: Decimal | None
    tol_minus: Decimal | None
    tol_plus: Decimal | None
    measured_value: Decimal | None
    measurement_method: str | None
    result: str | None
    notes: str | None


class FaiReportCreate(BaseModel):
    part_number: str = Field(..., min_length=1, max_length=128)
    part_name: str | None = Field(default=None, max_length=255)
    part_revision: str | None = Field(default=None, max_length=32)
    drawing_number: str | None = Field(default=None, max_length=128)
    fai_type: FaiType = FaiType.FULL
    inspection_id: int | None = None
    supplier_id: int | None = None
    baseline_part_number: str | None = Field(default=None, max_length=128)
    prepared_by: str | None = Field(default=None, max_length=255)
    fai_date: date | None = None
    characteristics: list[FaiCharacteristicCreate] = []


class FaiReportRead(ORMModel):
    id: int
    fai_number: str
    inspection_id: int | None
    part_number: str
    part_name: str | None
    part_revision: str | None
    drawing_number: str | None
    fai_type: FaiType
    supplier_id: int | None
    baseline_part_number: str | None
    disposition: str | None
    prepared_by: str | None
    fai_date: date | None
    created_at: datetime | None = None
    characteristics: list[FaiCharacteristicRead] = []


class InspectionRead(ORMModel):
    id: int
    inspection_number: str
    inspection_type: InspectionType
    result: InspectionResult
    part_number: str | None
    lot_number: str | None
    quantity_inspected: int | None
    quantity_accepted: int | None
    quantity_rejected: int | None
    inspector_id: int | None
    supplier_id: int | None
    work_order: str | None
    inspection_date: date | None
    nonconformance_id: int | None
    notes: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    fai_report: FaiReportRead | None = None


class InspectionList(ORMModel):
    id: int
    inspection_number: str
    inspection_type: InspectionType
    result: InspectionResult
    part_number: str | None
    inspection_date: date | None
    created_at: datetime | None = None
