"""Organization settings & branding schemas."""
from __future__ import annotations

import re

from pydantic import BaseModel, Field, field_validator

from app.schemas.common import ORMModel

# Branding logo accepts only http(s) URLs or inline data: image URIs.
_LOGO_URL_RE = re.compile(r"^(https?://|data:image/)", re.IGNORECASE)
# Accent color must be a 3- or 6-digit hex value (with leading #).
_HEX_COLOR_RE = re.compile(r"^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$")


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
    logo_url: str | None = Field(default=None, max_length=1_048_576)
    primary_color: str | None = Field(default=None, max_length=32)
    support_email: str | None = Field(default=None, max_length=255)
    default_review_cycle_days: int | None = Field(default=None, ge=0)
    calibration_default_interval_days: int | None = Field(default=None, ge=0)
    timezone: str | None = Field(default=None, max_length=64)

    @field_validator("logo_url")
    @classmethod
    def _validate_logo_url(cls, v: str | None) -> str | None:
        """Allow only http(s):// or data:image/... so injected markup is safe."""
        if v is None:
            return None
        v = v.strip()
        if v == "":
            return None
        if not _LOGO_URL_RE.match(v):
            raise ValueError("Logo URL must start with http://, https://, or data:image/")
        return v

    @field_validator("primary_color")
    @classmethod
    def _validate_primary_color(cls, v: str | None) -> str | None:
        """Accent color must be a hex value, e.g. #2563eb."""
        if v is None:
            return None
        v = v.strip()
        if v == "":
            return None
        if not _HEX_COLOR_RE.match(v):
            raise ValueError("Primary color must be a hex value, e.g. #2563eb")
        return v.lower()
