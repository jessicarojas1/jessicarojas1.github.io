"""Records Retention & Disposition Schedule schemas."""

from __future__ import annotations

from datetime import datetime

from pydantic import BaseModel, Field

from app.models.retention import (
    DispositionAction,
    RetentionCategory,
    RetentionStatus,
    RetentionTrigger,
)
from app.schemas.common import ORMModel


class RetentionBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    record_category: RetentionCategory = RetentionCategory.OTHER
    retention_trigger: RetentionTrigger = RetentionTrigger.CREATION
    retention_years: int | None = Field(default=None, ge=0)
    disposition_action: DispositionAction = DispositionAction.REVIEW
    legal_hold: bool = False
    authority_reference: str | None = Field(default=None, max_length=255)
    owner_id: int | None = None
    notes: str | None = None


class RetentionCreate(RetentionBase):
    pass


class RetentionUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    record_category: RetentionCategory | None = None
    retention_trigger: RetentionTrigger | None = None
    retention_years: int | None = Field(default=None, ge=0)
    disposition_action: DispositionAction | None = None
    legal_hold: bool | None = None
    authority_reference: str | None = Field(default=None, max_length=255)
    status: RetentionStatus | None = None
    owner_id: int | None = None
    notes: str | None = None


class RetentionRead(ORMModel):
    id: int
    policy_number: str
    title: str
    record_category: RetentionCategory
    retention_trigger: RetentionTrigger
    retention_years: int | None
    disposition_action: DispositionAction
    legal_hold: bool
    authority_reference: str | None
    status: RetentionStatus
    owner_id: int | None
    notes: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
