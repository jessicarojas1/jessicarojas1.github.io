"""Concession / Deviation / Waiver schemas."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.concession import ConcessionStatus, ConcessionType
from app.schemas.common import ORMModel


class ConcessionCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str = Field(..., min_length=1)
    concession_type: ConcessionType = ConcessionType.DEVIATION
    part_number: str | None = Field(default=None, max_length=128)
    justification: str | None = None
    quantity: int | None = Field(default=None, ge=0)
    supplier_id: int | None = None
    customer_approval_required: bool = False
    expiry_date: date | None = None


class ConcessionUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    description: str | None = Field(default=None, min_length=1)
    concession_type: ConcessionType | None = None
    part_number: str | None = Field(default=None, max_length=128)
    justification: str | None = None
    quantity: int | None = Field(default=None, ge=0)
    status: ConcessionStatus | None = None
    supplier_id: int | None = None
    customer_approval_required: bool | None = None
    customer_approved: bool | None = None
    expiry_date: date | None = None


class ConcessionRead(ORMModel):
    id: int
    concession_number: str
    concession_type: ConcessionType
    title: str
    part_number: str | None
    description: str
    justification: str | None
    quantity: int | None
    status: ConcessionStatus
    supplier_id: int | None
    customer_approval_required: bool
    customer_approved: bool
    expiry_date: date | None
