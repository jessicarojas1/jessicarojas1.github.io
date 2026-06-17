"""Continual Improvement / Kaizen schemas (clause 10.3)."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.improvement import (
    ImprovementCategory,
    ImprovementPriority,
    ImprovementStatus,
)
from app.schemas.common import ORMModel


class ImprovementCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str | None = None
    category: ImprovementCategory = ImprovementCategory.KAIZEN
    source: str | None = Field(default=None, max_length=255)
    owner_id: int | None = None
    priority: ImprovementPriority = ImprovementPriority.MEDIUM
    estimated_benefit: float | None = None
    target_date: date | None = None
    clause_ref: str | None = Field(default=None, max_length=64)


class ImprovementUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    description: str | None = None
    category: ImprovementCategory | None = None
    source: str | None = Field(default=None, max_length=255)
    owner_id: int | None = None
    status: ImprovementStatus | None = None
    priority: ImprovementPriority | None = None
    estimated_benefit: float | None = None
    realized_benefit: float | None = None
    target_date: date | None = None
    clause_ref: str | None = Field(default=None, max_length=64)


class ImprovementRead(ORMModel):
    id: int
    improvement_number: str
    title: str
    description: str | None
    category: ImprovementCategory
    source: str | None
    owner_id: int | None
    owner_name: str | None = None
    status: ImprovementStatus
    priority: ImprovementPriority
    estimated_benefit: float | None
    realized_benefit: float | None
    target_date: date | None
    clause_ref: str | None
