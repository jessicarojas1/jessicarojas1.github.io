"""Organization settings & branding schemas."""

from __future__ import annotations

import re
from datetime import datetime
from typing import Literal

from pydantic import BaseModel, Field, field_validator

from app.schemas.common import ORMModel

ReportFrequency = Literal["daily", "weekly", "monthly"]

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
    # Multi-channel notification delivery.
    notifications_email_enabled: bool = False
    teams_webhook_url: str | None = None
    slack_webhook_url: str | None = None
    # SLA escalation.
    sla_enabled: bool = True
    sla_capa_due_soon_days: int = 7
    sla_ncr_minor_days: int = 30
    sla_ncr_major_days: int = 14
    sla_ncr_critical_days: int = 7
    # Scheduled report digest.
    report_schedule_enabled: bool = False
    report_schedule_frequency: ReportFrequency = "weekly"
    report_schedule_recipients: str | None = None
    report_schedule_last_sent_at: datetime | None = None
    # Executive dashboard KPI targets.
    kpi_target_open_ncrs: float = 10
    kpi_target_overdue_capas: float = 0
    kpi_target_open_findings: float = 5
    kpi_target_escapes: float = 3
    kpi_target_capa_on_time: float = 90
    kpi_target_supplier_quality: float = 95
    kpi_target_supplier_otd: float = 95
    # Cost of Quality per-event unit costs.
    coq_cost_ncr: float = 500
    coq_cost_complaint: float = 2000
    coq_cost_inspection: float = 75
    coq_cost_audit: float = 1500
    coq_cost_capa: float = 1200


class OrgSettingsUpdate(BaseModel):
    organization_name: str | None = Field(default=None, max_length=255)
    logo_url: str | None = Field(default=None, max_length=1_048_576)
    primary_color: str | None = Field(default=None, max_length=32)
    support_email: str | None = Field(default=None, max_length=255)
    default_review_cycle_days: int | None = Field(default=None, ge=0)
    calibration_default_interval_days: int | None = Field(default=None, ge=0)
    timezone: str | None = Field(default=None, max_length=64)
    # Multi-channel notification delivery.
    notifications_email_enabled: bool | None = None
    teams_webhook_url: str | None = Field(default=None, max_length=1024)
    slack_webhook_url: str | None = Field(default=None, max_length=1024)
    # SLA escalation.
    sla_enabled: bool | None = None
    sla_capa_due_soon_days: int | None = Field(default=None, ge=0, le=3650)
    sla_ncr_minor_days: int | None = Field(default=None, ge=0, le=3650)
    sla_ncr_major_days: int | None = Field(default=None, ge=0, le=3650)
    sla_ncr_critical_days: int | None = Field(default=None, ge=0, le=3650)
    # Scheduled report digest.
    report_schedule_enabled: bool | None = None
    report_schedule_frequency: ReportFrequency | None = None
    report_schedule_recipients: str | None = Field(default=None, max_length=8192)
    # Executive dashboard KPI targets (>= 0).
    kpi_target_open_ncrs: float | None = Field(default=None, ge=0)
    kpi_target_overdue_capas: float | None = Field(default=None, ge=0)
    kpi_target_open_findings: float | None = Field(default=None, ge=0)
    kpi_target_escapes: float | None = Field(default=None, ge=0)
    kpi_target_capa_on_time: float | None = Field(default=None, ge=0, le=100)
    kpi_target_supplier_quality: float | None = Field(default=None, ge=0, le=100)
    kpi_target_supplier_otd: float | None = Field(default=None, ge=0, le=100)
    # Cost of Quality per-event unit costs (>= 0).
    coq_cost_ncr: float | None = Field(default=None, ge=0)
    coq_cost_complaint: float | None = Field(default=None, ge=0)
    coq_cost_inspection: float | None = Field(default=None, ge=0)
    coq_cost_audit: float | None = Field(default=None, ge=0)
    coq_cost_capa: float | None = Field(default=None, ge=0)

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


class NotificationTestRequest(BaseModel):
    channel: Literal["email", "teams", "slack"]


class NotificationTestResult(BaseModel):
    ok: bool
    detail: str


class SlaSweepResult(BaseModel):
    """Summary of an SLA escalation sweep."""

    enabled: bool
    capa_overdue: int = 0
    capa_due_soon: int = 0
    capa_action_overdue: int = 0
    ncr_overdue: int = 0


class DigestSendRequest(BaseModel):
    """Optional recipient override for a manual digest send."""

    recipients: list[str] | None = None


class DigestSendResult(BaseModel):
    ok: bool
    sent: int = 0
    detail: str
