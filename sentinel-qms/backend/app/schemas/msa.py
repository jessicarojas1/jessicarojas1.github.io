"""MSA / Gage R&R schemas."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.msa import MsaResult, MsaType
from app.schemas.common import ORMModel


class MsaCreate(BaseModel):
    characteristic: str = Field(..., min_length=1, max_length=255)
    equipment_id: int | None = None
    study_type: MsaType = MsaType.GAGE_RR
    num_parts: int | None = Field(default=None, ge=0)
    num_operators: int | None = Field(default=None, ge=0)
    num_trials: int | None = Field(default=None, ge=0)
    grr_percent: float | None = Field(default=None, ge=0)
    ndc: int | None = Field(default=None, ge=0)
    study_date: date | None = None
    notes: str | None = None


class MsaUpdate(BaseModel):
    characteristic: str | None = Field(default=None, min_length=1, max_length=255)
    equipment_id: int | None = None
    study_type: MsaType | None = None
    num_parts: int | None = Field(default=None, ge=0)
    num_operators: int | None = Field(default=None, ge=0)
    num_trials: int | None = Field(default=None, ge=0)
    grr_percent: float | None = Field(default=None, ge=0)
    ndc: int | None = Field(default=None, ge=0)
    study_date: date | None = None
    notes: str | None = None


class MsaRead(ORMModel):
    id: int
    study_number: str
    equipment_id: int | None
    characteristic: str
    study_type: MsaType
    num_parts: int | None
    num_operators: int | None
    num_trials: int | None
    grr_percent: float | None
    ndc: int | None
    result: MsaResult
    study_date: date | None
    notes: str | None
