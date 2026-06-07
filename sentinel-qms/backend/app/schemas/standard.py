"""Standards & coverage-mapping schemas."""

from __future__ import annotations

from pydantic import BaseModel, Field

from app.models.standard import CoverageStatus
from app.schemas.common import ORMModel


class RequirementRead(ORMModel):
    id: int
    standard_id: int
    clause: str
    title: str
    module_key: str | None
    coverage_status: CoverageStatus
    evidence_note: str | None


class RequirementCreate(BaseModel):
    clause: str = Field(..., min_length=1, max_length=64)
    title: str = Field(..., min_length=1, max_length=512)
    module_key: str | None = Field(default=None, max_length=64)
    coverage_status: CoverageStatus = CoverageStatus.GAP
    evidence_note: str | None = None


class RequirementUpdate(BaseModel):
    clause: str | None = Field(default=None, min_length=1, max_length=64)
    title: str | None = Field(default=None, min_length=1, max_length=512)
    module_key: str | None = Field(default=None, max_length=64)
    coverage_status: CoverageStatus | None = None
    evidence_note: str | None = None


class CoverageSummary(BaseModel):
    total: int
    covered: int
    partial: int
    gap: int
    not_applicable: int
    coverage_pct: float


class StandardList(ORMModel):
    id: int
    code: str
    name: str
    description: str | None
    is_active: bool
    coverage: CoverageSummary


class StandardRead(StandardList):
    requirements: list[RequirementRead]


class StandardCreate(BaseModel):
    code: str = Field(..., min_length=1, max_length=64)
    name: str = Field(..., min_length=1, max_length=255)
    description: str | None = None


class StandardUpdate(BaseModel):
    code: str | None = Field(default=None, min_length=1, max_length=64)
    name: str | None = Field(default=None, min_length=1, max_length=255)
    description: str | None = None
    is_active: bool | None = None
