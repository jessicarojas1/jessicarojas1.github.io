"""APQP / PPAP (AS9145) schemas."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.apqp import ApqpPhase, ApqpStatus, PpapElementStatus
from app.schemas.common import ORMModel


class PpapElementRead(ORMModel):
    id: int
    project_id: int
    element_key: str
    name: str
    status: PpapElementStatus
    notes: str | None


class PpapElementUpdate(BaseModel):
    status: PpapElementStatus | None = None
    notes: str | None = None


class PpapProgress(BaseModel):
    total: int
    approved: int
    applicable: int
    approved_pct: float


class ApqpCreate(BaseModel):
    part_number: str = Field(..., min_length=1, max_length=128)
    part_name: str = Field(..., min_length=1, max_length=255)
    customer: str | None = Field(default=None, max_length=255)
    supplier_id: int | None = None
    contract_id: int | None = None
    submission_level: int = Field(default=3, ge=1, le=5)
    target_date: date | None = None
    notes: str | None = None


class ApqpUpdate(BaseModel):
    part_number: str | None = Field(default=None, min_length=1, max_length=128)
    part_name: str | None = Field(default=None, min_length=1, max_length=255)
    customer: str | None = Field(default=None, max_length=255)
    supplier_id: int | None = None
    contract_id: int | None = None
    current_phase: ApqpPhase | None = None
    status: ApqpStatus | None = None
    submission_level: int | None = Field(default=None, ge=1, le=5)
    target_date: date | None = None
    notes: str | None = None


class ApqpList(ORMModel):
    id: int
    project_number: str
    part_number: str
    part_name: str
    customer: str | None
    supplier_id: int | None
    contract_id: int | None
    current_phase: ApqpPhase
    status: ApqpStatus
    submission_level: int
    target_date: date | None
    ppap: PpapProgress


class ApqpRead(ApqpList):
    notes: str | None
    elements: list[PpapElementRead]
