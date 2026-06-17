"""FMEA (PFMEA/DFMEA) schemas with RPN + action priority."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.fmea import FmeaItemStatus, FmeaStatus, FmeaType
from app.schemas.common import ORMModel

_RATING = Field(default=1, ge=1, le=10)


def rpn_of(severity: int, occurrence: int, detection: int) -> int:
    return severity * occurrence * detection


def action_priority(severity: int, rpn: int) -> str:
    """Coarse AIAG-style action priority from RPN + severity."""
    if rpn >= 200 or severity >= 9:
        return "high"
    if rpn >= 80:
        return "medium"
    return "low"


class FmeaItemCreate(BaseModel):
    function: str = Field(..., min_length=1, max_length=512)
    failure_mode: str = Field(..., min_length=1, max_length=512)
    effect: str | None = None
    cause: str | None = None
    controls: str | None = None
    severity: int = _RATING
    occurrence: int = _RATING
    detection: int = _RATING
    recommended_action: str | None = None
    action_owner_id: int | None = None
    target_date: date | None = None


class FmeaItemUpdate(BaseModel):
    function: str | None = Field(default=None, min_length=1, max_length=512)
    failure_mode: str | None = Field(default=None, min_length=1, max_length=512)
    effect: str | None = None
    cause: str | None = None
    controls: str | None = None
    severity: int | None = Field(default=None, ge=1, le=10)
    occurrence: int | None = Field(default=None, ge=1, le=10)
    detection: int | None = Field(default=None, ge=1, le=10)
    recommended_action: str | None = None
    action_owner_id: int | None = None
    target_date: date | None = None
    status: FmeaItemStatus | None = None


class FmeaItemRead(ORMModel):
    id: int
    fmea_id: int
    function: str
    failure_mode: str
    effect: str | None
    cause: str | None
    controls: str | None
    severity: int
    occurrence: int
    detection: int
    recommended_action: str | None
    action_owner_id: int | None
    target_date: date | None
    status: FmeaItemStatus
    rpn: int = 0
    action_priority: str = "low"


class FmeaCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    fmea_type: FmeaType = FmeaType.PROCESS
    part_number: str | None = Field(default=None, max_length=128)
    process_ref: str | None = Field(default=None, max_length=255)
    scope: str | None = None
    owner_id: int | None = None


class FmeaUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    fmea_type: FmeaType | None = None
    part_number: str | None = Field(default=None, max_length=128)
    process_ref: str | None = Field(default=None, max_length=255)
    scope: str | None = None
    owner_id: int | None = None
    status: FmeaStatus | None = None


class FmeaList(ORMModel):
    id: int
    fmea_number: str
    title: str
    fmea_type: FmeaType
    part_number: str | None
    owner_id: int | None
    owner_name: str | None = None
    status: FmeaStatus
    item_count: int = 0
    max_rpn: int = 0


class FmeaRead(FmeaList):
    process_ref: str | None
    scope: str | None
    items: list[FmeaItemRead]
