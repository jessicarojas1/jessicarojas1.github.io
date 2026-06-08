"""Supplier, SCAR, ASL, and rating schemas."""

from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel, Field

from app.models.supplier import ScarStatus, SupplierStatus
from app.schemas.common import ORMModel


class SupplierBase(BaseModel):
    name: str = Field(..., min_length=1, max_length=255)
    status: SupplierStatus = SupplierStatus.PROSPECTIVE
    cage_code: str | None = Field(default=None, max_length=16)
    duns_number: str | None = Field(default=None, max_length=16)
    certification: str | None = Field(default=None, max_length=128)
    cert_expiry: date | None = None
    contact_name: str | None = Field(default=None, max_length=255)
    contact_email: str | None = None
    country: str | None = Field(default=None, max_length=64)
    notes: str | None = None


class SupplierCreate(SupplierBase):
    pass


class SupplierUpdate(BaseModel):
    name: str | None = Field(default=None, max_length=255)
    status: SupplierStatus | None = None
    cage_code: str | None = Field(default=None, max_length=16)
    duns_number: str | None = Field(default=None, max_length=16)
    certification: str | None = Field(default=None, max_length=128)
    cert_expiry: date | None = None
    contact_name: str | None = Field(default=None, max_length=255)
    contact_email: str | None = None
    country: str | None = Field(default=None, max_length=64)
    notes: str | None = None


class ScarCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str = Field(..., min_length=1)
    nonconformance_id: int | None = None
    issued_date: date | None = None
    response_due_date: date | None = None


class ScarUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    description: str | None = None
    status: ScarStatus | None = None
    supplier_response: str | None = None
    response_due_date: date | None = None


class ScarRead(ORMModel):
    id: int
    scar_number: str
    supplier_id: int
    title: str
    description: str
    status: ScarStatus
    nonconformance_id: int | None
    capa_id: int | None
    issued_date: date | None
    response_due_date: date | None
    supplier_response: str | None
    closed_at: datetime | None
    created_at: datetime | None = None


class AslEntryCreate(BaseModel):
    commodity: str = Field(..., min_length=1, max_length=255)
    process_scope: str | None = None
    approved_date: date | None = None
    expiry_date: date | None = None
    is_active: bool = True


class AslEntryRead(ORMModel):
    id: int
    supplier_id: int
    commodity: str
    process_scope: str | None
    approved_date: date | None
    expiry_date: date | None
    is_active: bool


class RatingCreate(BaseModel):
    period: str = Field(..., min_length=1, max_length=16)
    quality_score: Decimal | None = Field(default=None, ge=0, le=100)
    on_time_delivery: Decimal | None = Field(default=None, ge=0, le=100)
    ppm_defects: int | None = Field(default=None, ge=0)
    notes: str | None = None


class RatingRead(ORMModel):
    id: int
    supplier_id: int
    period: str
    quality_score: Decimal | None
    on_time_delivery: Decimal | None
    ppm_defects: int | None
    composite_score: Decimal | None
    grade: str | None
    notes: str | None
    created_at: datetime | None = None


class SupplierRead(ORMModel):
    id: int
    supplier_code: str
    name: str
    status: SupplierStatus
    cage_code: str | None
    duns_number: str | None
    certification: str | None
    cert_expiry: date | None
    contact_name: str | None
    contact_email: str | None
    country: str | None
    notes: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None


class SupplierList(ORMModel):
    id: int
    supplier_code: str
    name: str
    status: SupplierStatus
    certification: str | None
    country: str | None
    created_at: datetime | None = None
