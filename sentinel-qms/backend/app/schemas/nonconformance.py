"""Nonconformance (NCR) and disposition schemas."""

from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel, Field

from app.models.nonconformance import DispositionType, NcSeverity, NcStatus
from app.schemas.common import ESignatureIn, ORMModel


class NonconformanceBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str = Field(..., min_length=1)
    severity: NcSeverity = NcSeverity.MINOR
    part_number: str | None = Field(default=None, max_length=128)
    lot_number: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    quantity_affected: int | None = Field(default=None, ge=0)
    estimated_cost: Decimal | None = Field(default=None, ge=0)
    source: str | None = Field(default=None, max_length=64)
    detected_at: date | None = None
    work_order: str | None = Field(default=None, max_length=128)
    supplier_id: int | None = None
    assigned_to: int | None = None


class NonconformanceCreate(NonconformanceBase):
    pass


class NonconformanceUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    description: str | None = None
    severity: NcSeverity | None = None
    part_number: str | None = Field(default=None, max_length=128)
    lot_number: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    quantity_affected: int | None = Field(default=None, ge=0)
    estimated_cost: Decimal | None = Field(default=None, ge=0)
    source: str | None = Field(default=None, max_length=64)
    detected_at: date | None = None
    work_order: str | None = Field(default=None, max_length=128)
    supplier_id: int | None = None
    assigned_to: int | None = None


class NcStatusChange(BaseModel):
    status: NcStatus


class DispositionCreate(BaseModel):
    disposition_type: DispositionType
    justification: str = Field(..., min_length=1)
    mrb_members: str | None = None
    customer_approval_required: bool = False
    customer_approved: bool = False
    signature: ESignatureIn


class DispositionRead(ORMModel):
    id: int
    nonconformance_id: int
    disposition_type: DispositionType
    justification: str
    mrb_members: str | None
    customer_approval_required: bool
    customer_approved: bool
    decided_by: int
    signature_id: int | None
    created_at: datetime | None = None


class NonconformanceRead(ORMModel):
    id: int
    ncr_number: str
    title: str
    description: str
    severity: NcSeverity
    status: NcStatus
    part_number: str | None
    lot_number: str | None
    serial_number: str | None
    quantity_affected: int | None
    estimated_cost: Decimal | None
    source: str | None
    detected_at: date | None
    work_order: str | None
    supplier_id: int | None
    assigned_to: int | None
    capa_id: int | None
    closed_at: datetime | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    dispositions: list[DispositionRead] = []


class NonconformanceList(ORMModel):
    id: int
    ncr_number: str
    title: str
    severity: NcSeverity
    status: NcStatus
    part_number: str | None
    supplier_id: int | None
    created_at: datetime | None = None
