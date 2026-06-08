"""Internal audit program schemas."""

from __future__ import annotations

from pydantic import BaseModel, Field

from app.models.audit_program import ProgramItemStatus, ProgramStatus
from app.schemas.common import ORMModel


class ProgramItemCreate(BaseModel):
    area: str = Field(..., min_length=1, max_length=255)
    clause_reference: str | None = Field(default=None, max_length=64)
    planned_period: str | None = Field(default=None, max_length=32)
    lead_auditor_id: int | None = None


class ProgramItemUpdate(BaseModel):
    area: str | None = Field(default=None, min_length=1, max_length=255)
    clause_reference: str | None = Field(default=None, max_length=64)
    planned_period: str | None = Field(default=None, max_length=32)
    lead_auditor_id: int | None = None
    status: ProgramItemStatus | None = None
    audit_id: int | None = None


class ProgramItemRead(ORMModel):
    id: int
    program_id: int
    area: str
    clause_reference: str | None
    planned_period: str | None
    lead_auditor_id: int | None
    status: ProgramItemStatus
    audit_id: int | None


class ProgramProgress(BaseModel):
    total: int
    completed: int
    completed_pct: float


class AuditProgramCreate(BaseModel):
    name: str = Field(..., min_length=1, max_length=255)
    year: int = Field(..., ge=2000, le=2100)
    objectives: str | None = None


class AuditProgramUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    year: int | None = Field(default=None, ge=2000, le=2100)
    objectives: str | None = None
    status: ProgramStatus | None = None


class AuditProgramList(ORMModel):
    id: int
    name: str
    year: int
    status: ProgramStatus
    progress: ProgramProgress


class AuditProgramRead(AuditProgramList):
    objectives: str | None
    items: list[ProgramItemRead]
