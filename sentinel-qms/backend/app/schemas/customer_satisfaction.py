"""Customer Satisfaction survey schemas (clause 9.1.2)."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.customer_satisfaction import SurveyMethod
from app.schemas.common import ORMModel

_SCORE = Field(default=None, ge=0, le=100)


def overall_of(
    quality: float | None,
    delivery: float | None,
    communication: float | None,
    explicit: float | None,
) -> float | None:
    """Explicit overall if given, else the mean of the provided sub-scores."""
    if explicit is not None:
        return round(explicit, 1)
    parts = [s for s in (quality, delivery, communication) if s is not None]
    return round(sum(parts) / len(parts), 1) if parts else None


class SurveyCreate(BaseModel):
    customer_id: int
    period: str | None = Field(default=None, max_length=32)
    survey_date: date | None = None
    method: SurveyMethod = SurveyMethod.SURVEY
    quality_score: float | None = _SCORE
    delivery_score: float | None = _SCORE
    communication_score: float | None = _SCORE
    overall_score: float | None = _SCORE
    respondent: str | None = Field(default=None, max_length=255)
    comments: str | None = None


class SurveyUpdate(BaseModel):
    period: str | None = Field(default=None, max_length=32)
    survey_date: date | None = None
    method: SurveyMethod | None = None
    quality_score: float | None = _SCORE
    delivery_score: float | None = _SCORE
    communication_score: float | None = _SCORE
    overall_score: float | None = _SCORE
    respondent: str | None = Field(default=None, max_length=255)
    comments: str | None = None


class SurveyRead(ORMModel):
    id: int
    survey_number: str
    customer_id: int
    customer_name: str | None = None
    period: str | None
    survey_date: date | None
    method: SurveyMethod
    quality_score: float | None
    delivery_score: float | None
    communication_score: float | None
    overall_score: float | None
    respondent: str | None
    comments: str | None
