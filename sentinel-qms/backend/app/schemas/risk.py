"""Risk register schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.risk import RiskCategory, RiskStatus, TreatmentStrategy
from app.schemas.common import ORMModel

_SCALE = Field(..., ge=1, le=10)
_SCALE_OPT = Field(default=None, ge=1, le=10)


class RiskBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    category: RiskCategory = RiskCategory.QUALITY
    description: str = Field(..., min_length=1)
    is_opportunity: bool = False
    severity: int = _SCALE
    likelihood: int = _SCALE
    detectability: int = _SCALE
    treatment_strategy: TreatmentStrategy | None = None
    treatment_plan: str | None = None
    owner_id: int | None = None
    review_date: date | None = None


class RiskCreate(RiskBase):
    pass


class RiskUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    category: RiskCategory | None = None
    status: RiskStatus | None = None
    description: str | None = None
    is_opportunity: bool | None = None
    severity: int | None = _SCALE_OPT
    likelihood: int | None = _SCALE_OPT
    detectability: int | None = _SCALE_OPT
    treatment_strategy: TreatmentStrategy | None = None
    treatment_plan: str | None = None
    residual_severity: int | None = _SCALE_OPT
    residual_likelihood: int | None = _SCALE_OPT
    residual_detectability: int | None = _SCALE_OPT
    owner_id: int | None = None
    review_date: date | None = None
    # Optimistic-concurrency token: the updated_at the client last saw. When
    # supplied and stale, the update is rejected with 409 (lost-update guard).
    # Omitting it preserves legacy last-write-wins behavior.
    expected_updated_at: datetime | None = None


class RiskRead(ORMModel):
    id: int
    risk_number: str
    title: str
    category: RiskCategory
    status: RiskStatus
    description: str
    is_opportunity: bool
    severity: int
    likelihood: int
    detectability: int
    rpn: int
    treatment_strategy: TreatmentStrategy | None
    treatment_plan: str | None
    residual_severity: int | None
    residual_likelihood: int | None
    residual_detectability: int | None
    residual_rpn: int | None
    owner_id: int | None
    review_date: date | None
    capa_id: int | None
    created_at: datetime | None = None
    updated_at: datetime | None = None


class RiskList(ORMModel):
    id: int
    risk_number: str
    title: str
    category: RiskCategory
    status: RiskStatus
    rpn: int
    residual_rpn: int | None
    created_at: datetime | None = None
