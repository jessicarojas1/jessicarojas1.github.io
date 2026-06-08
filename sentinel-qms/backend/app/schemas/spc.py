"""Key Characteristics & SPC schemas."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.spc import KcClass
from app.schemas.common import ORMModel


class MeasurementCreate(BaseModel):
    value: float
    measured_at: date | None = None
    operator: str | None = Field(default=None, max_length=128)


class MeasurementRead(ORMModel):
    id: int
    kc_id: int
    value: float
    measured_at: date | None
    operator: str | None


class Capability(BaseModel):
    count: int
    mean: float | None
    std: float | None
    cp: float | None
    cpk: float | None
    ucl: float | None
    lcl: float | None
    min: float | None
    max: float | None


class KcCreate(BaseModel):
    part_number: str = Field(..., min_length=1, max_length=128)
    characteristic: str = Field(..., min_length=1, max_length=255)
    nominal: float | None = None
    usl: float | None = None
    lsl: float | None = None
    unit: str | None = Field(default=None, max_length=32)
    kc_class: KcClass = KcClass.MAJOR
    notes: str | None = None


class KcUpdate(BaseModel):
    part_number: str | None = Field(default=None, min_length=1, max_length=128)
    characteristic: str | None = Field(default=None, min_length=1, max_length=255)
    nominal: float | None = None
    usl: float | None = None
    lsl: float | None = None
    unit: str | None = Field(default=None, max_length=32)
    kc_class: KcClass | None = None
    notes: str | None = None


class KcList(ORMModel):
    id: int
    kc_number: str
    part_number: str
    characteristic: str
    nominal: float | None
    usl: float | None
    lsl: float | None
    unit: str | None
    kc_class: KcClass
    capability: Capability


class SpcViolation(BaseModel):
    rule: int
    index: int
    value: float
    description: str


class KcRead(KcList):
    notes: str | None
    measurements: list[MeasurementRead]
    violations: list[SpcViolation]
