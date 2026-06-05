"""Audit management schemas."""
from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.audit_mgmt import (
    AuditStatus,
    AuditType,
    FindingStatus,
    FindingType,
)
from app.schemas.common import ORMModel


class AuditBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    audit_type: AuditType
    standard: str | None = Field(default=None, max_length=64)
    scope: str | None = None
    lead_auditor_id: int | None = None
    auditee_area: str | None = Field(default=None, max_length=255)
    supplier_id: int | None = None
    planned_date: date | None = None


class AuditCreate(AuditBase):
    pass


class AuditUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    audit_type: AuditType | None = None
    status: AuditStatus | None = None
    standard: str | None = Field(default=None, max_length=64)
    scope: str | None = None
    lead_auditor_id: int | None = None
    auditee_area: str | None = Field(default=None, max_length=255)
    supplier_id: int | None = None
    planned_date: date | None = None
    actual_date: date | None = None


class FindingCreate(BaseModel):
    finding_type: FindingType
    clause_reference: str | None = Field(default=None, max_length=64)
    description: str = Field(..., min_length=1)
    evidence: str | None = None
    response_due_date: date | None = None


class FindingUpdate(BaseModel):
    finding_type: FindingType | None = None
    status: FindingStatus | None = None
    clause_reference: str | None = Field(default=None, max_length=64)
    description: str | None = None
    evidence: str | None = None
    response_due_date: date | None = None


class FindingRead(ORMModel):
    id: int
    audit_id: int
    finding_number: str
    finding_type: FindingType
    status: FindingStatus
    clause_reference: str | None
    description: str
    evidence: str | None
    response_due_date: date | None
    capa_id: int | None
    created_at: datetime | None = None


class FindingLinkCapa(BaseModel):
    capa_id: int


class ChecklistItemCreate(BaseModel):
    clause_reference: str | None = Field(default=None, max_length=64)
    question: str = Field(..., min_length=1)
    result: str | None = Field(default=None, max_length=16)
    notes: str | None = None


class ChecklistItemRead(ORMModel):
    id: int
    audit_id: int
    clause_reference: str | None
    question: str
    result: str | None
    notes: str | None


class AuditRead(ORMModel):
    id: int
    audit_number: str
    title: str
    audit_type: AuditType
    status: AuditStatus
    standard: str | None
    scope: str | None
    lead_auditor_id: int | None
    auditee_area: str | None
    supplier_id: int | None
    planned_date: date | None
    actual_date: date | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    findings: list[FindingRead] = []
    checklist_items: list[ChecklistItemRead] = []


class AuditList(ORMModel):
    id: int
    audit_number: str
    title: str
    audit_type: AuditType
    status: AuditStatus
    standard: str | None
    planned_date: date | None
    created_at: datetime | None = None
