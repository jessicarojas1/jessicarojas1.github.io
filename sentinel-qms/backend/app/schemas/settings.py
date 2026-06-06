"""Organization settings & branding schemas."""
from __future__ import annotations

from pydantic import BaseModel, Field

from app.schemas.common import ORMModel


class OrgSettingsRead(ORMModel):
    id: int
    organization_name: str
    logo_url: str | None = None
    primary_color: str | None = None
    support_email: str | None = None
    default_review_cycle_days: int
    calibration_default_interval_days: int
    timezone: str


class OrgSettingsUpdate(BaseModel):
    organization_name: str | None = Field(default=None, max_length=255)
    logo_url: str | None = Field(default=None, max_length=1024)
    primary_color: str | None = Field(default=None, max_length=32)
    support_email: str | None = Field(default=None, max_length=255)
    default_review_cycle_days: int | None = Field(default=None, ge=0)
    calibration_default_interval_days: int | None = Field(default=None, ge=0)
    timezone: str | None = Field(default=None, max_length=64)
