"""Quality Objectives & KPIs schemas (clause 6.2)."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.quality_objective import (
    ObjectiveCadence,
    ObjectiveDirection,
    ObjectiveStatus,
)
from app.schemas.common import ORMModel


def attainment_pct(
    current: float | None, target: float, direction: ObjectiveDirection
) -> float | None:
    """Percent attainment of an objective vs its target (None until measured).

    ``higher_better``: current / target. ``lower_better``: target / current.
    Capped at 0..200% so a single outlier can't distort dashboards.
    """
    if current is None:
        return None
    try:
        if direction == ObjectiveDirection.LOWER_BETTER:
            pct = 200.0 if current <= 0 else (target / current) * 100.0
        else:
            pct = 0.0 if target == 0 else (current / target) * 100.0
    except ZeroDivisionError:  # pragma: no cover - guarded above
        return None
    return round(max(0.0, min(pct, 200.0)), 1)


class MeasurementCreate(BaseModel):
    value: float
    measured_at: date | None = None
    note: str | None = Field(default=None, max_length=512)


class MeasurementRead(ORMModel):
    id: int
    objective_id: int
    value: float
    measured_at: date | None
    note: str | None


class QualityObjectiveCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    description: str | None = None
    category: str | None = Field(default=None, max_length=128)
    owner_id: int | None = None
    target_value: float
    baseline_value: float | None = None
    unit: str | None = Field(default=None, max_length=32)
    direction: ObjectiveDirection = ObjectiveDirection.HIGHER_BETTER
    cadence: ObjectiveCadence = ObjectiveCadence.QUARTERLY
    target_date: date | None = None
    clause_ref: str | None = Field(default=None, max_length=64)


class QualityObjectiveUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    description: str | None = None
    category: str | None = Field(default=None, max_length=128)
    owner_id: int | None = None
    target_value: float | None = None
    baseline_value: float | None = None
    current_value: float | None = None
    unit: str | None = Field(default=None, max_length=32)
    direction: ObjectiveDirection | None = None
    cadence: ObjectiveCadence | None = None
    status: ObjectiveStatus | None = None
    target_date: date | None = None
    clause_ref: str | None = Field(default=None, max_length=64)


class QualityObjectiveList(ORMModel):
    id: int
    objective_number: str
    title: str
    category: str | None
    owner_id: int | None
    owner_name: str | None = None
    target_value: float
    baseline_value: float | None
    current_value: float | None
    unit: str | None
    direction: ObjectiveDirection
    cadence: ObjectiveCadence
    status: ObjectiveStatus
    target_date: date | None
    clause_ref: str | None
    attainment_pct: float | None = None


class QualityObjectiveRead(QualityObjectiveList):
    description: str | None
    measurements: list[MeasurementRead]
