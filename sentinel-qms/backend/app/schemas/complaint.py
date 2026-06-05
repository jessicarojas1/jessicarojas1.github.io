"""Customer complaint / RMA schemas."""
from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.complaint import ComplaintSeverity, ComplaintStatus
from app.schemas.common import ORMModel


class ComplaintBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str = Field(..., min_length=1)
    severity: ComplaintSeverity = ComplaintSeverity.MEDIUM
    customer_name: str = Field(..., min_length=1, max_length=255)
    customer_contact: str | None = Field(default=None, max_length=255)
    part_number: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    rma_number: str | None = Field(default=None, max_length=64)
    is_rma: bool = False
    received_date: date | None = None
    response_due_date: date | None = None
    assigned_to: int | None = None


class ComplaintCreate(ComplaintBase):
    pass


class ComplaintUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    description: str | None = None
    status: ComplaintStatus | None = None
    severity: ComplaintSeverity | None = None
    customer_name: str | None = Field(default=None, max_length=255)
    customer_contact: str | None = Field(default=None, max_length=255)
    part_number: str | None = Field(default=None, max_length=128)
    serial_number: str | None = Field(default=None, max_length=128)
    rma_number: str | None = Field(default=None, max_length=64)
    is_rma: bool | None = None
    received_date: date | None = None
    response_due_date: date | None = None
    resolution: str | None = None
    assigned_to: int | None = None
    nonconformance_id: int | None = None
    capa_id: int | None = None


class ComplaintRead(ORMModel):
    id: int
    complaint_number: str
    title: str
    description: str
    status: ComplaintStatus
    severity: ComplaintSeverity
    customer_name: str
    customer_contact: str | None
    part_number: str | None
    serial_number: str | None
    rma_number: str | None
    is_rma: bool
    received_date: date | None
    response_due_date: date | None
    resolution: str | None
    assigned_to: int | None
    nonconformance_id: int | None
    capa_id: int | None
    closed_at: datetime | None
    created_at: datetime | None = None
    updated_at: datetime | None = None


class ComplaintList(ORMModel):
    id: int
    complaint_number: str
    title: str
    status: ComplaintStatus
    severity: ComplaintSeverity
    customer_name: str
    is_rma: bool
    created_at: datetime | None = None
