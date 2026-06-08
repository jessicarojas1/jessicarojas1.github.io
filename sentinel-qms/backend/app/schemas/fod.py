"""FOD (Foreign Object Debris) program schemas (AS9146)."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.fod import FodRisk, FodSeverity, FodStatus
from app.schemas.common import ORMModel


# ---- Zones ----
class FodZoneCreate(BaseModel):
    code: str = Field(..., min_length=1, max_length=64)
    name: str = Field(..., min_length=1, max_length=255)
    risk_level: FodRisk = FodRisk.MEDIUM
    description: str | None = None


class FodZoneUpdate(BaseModel):
    code: str | None = Field(default=None, min_length=1, max_length=64)
    name: str | None = Field(default=None, min_length=1, max_length=255)
    risk_level: FodRisk | None = None
    description: str | None = None
    is_active: bool | None = None


class FodZoneRead(ORMModel):
    id: int
    code: str
    name: str
    risk_level: FodRisk
    description: str | None
    is_active: bool


# ---- Events ----
class FodEventCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    zone_id: int | None = None
    description: str | None = None
    object_type: str | None = Field(default=None, max_length=255)
    location: str | None = Field(default=None, max_length=255)
    severity: FodSeverity = FodSeverity.MEDIUM
    discovered_date: date | None = None


class FodEventUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    zone_id: int | None = None
    description: str | None = None
    object_type: str | None = Field(default=None, max_length=255)
    location: str | None = Field(default=None, max_length=255)
    severity: FodSeverity | None = None
    status: FodStatus | None = None
    discovered_date: date | None = None
    root_cause: str | None = None
    corrective_action: str | None = None


class FodEventRead(ORMModel):
    id: int
    event_number: str
    zone_id: int | None
    title: str
    description: str | None
    object_type: str | None
    location: str | None
    severity: FodSeverity
    status: FodStatus
    discovered_date: date | None
    root_cause: str | None
    corrective_action: str | None
    ncr_id: int | None


class FodNcrLinkResult(BaseModel):
    ncr_id: int
    ncr_number: str
