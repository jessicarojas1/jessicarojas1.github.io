"""Organization-wide settings & branding — a single-row (singleton) table."""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import (
    Boolean,
    DateTime,
    Integer,
    Numeric,
    String,
    Text,
    false,
    text,
    true,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import TimestampMixin


class OrgSettings(Base, TimestampMixin):
    """Singleton organization settings row (intended id=1).

    Holds branding (name/logo/primary color), contact, and default cadence
    settings shared across the QMS. Exactly one row is expected; the API
    creates it on first read when missing.
    """

    __tablename__ = "org_settings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_name: Mapped[str] = mapped_column(
        String(255), default="Sentinel QMS", nullable=False
    )
    # Text (not a bounded VARCHAR) so an uploaded logo stored as a data: URL fits.
    logo_url: Mapped[str | None] = mapped_column(Text, nullable=True)
    primary_color: Mapped[str | None] = mapped_column(String(32), nullable=True)
    support_email: Mapped[str | None] = mapped_column(String(255), nullable=True)
    default_review_cycle_days: Mapped[int] = mapped_column(Integer, default=365, nullable=False)
    calibration_default_interval_days: Mapped[int] = mapped_column(
        Integer, default=365, nullable=False
    )
    timezone: Mapped[str] = mapped_column(String(64), default="UTC", nullable=False)

    # ── Multi-channel notification delivery (admin-configurable) ──────────────
    # Master toggle for outbound email delivery (SMTP host/credentials still come
    # from env — see app.core.config). Teams/Slack webhook URLs configured here
    # override the env fallbacks (TEAMS_WEBHOOK_URL / SLACK_WEBHOOK_URL).
    notifications_email_enabled: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default=false(), nullable=False
    )
    teams_webhook_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    slack_webhook_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)

    # ── SLA escalation (auto-notify on overdue / due-soon NCRs & CAPAs) ───────
    # Master toggle plus per-record-type SLA windows. NCR windows are measured
    # from detection/creation by severity; CAPA "due soon" is measured from the
    # CAPA ``due_date``. Overdue is always relative to the CAPA ``due_date``.
    sla_enabled: Mapped[bool] = mapped_column(
        Boolean, default=True, server_default=true(), nullable=False
    )
    sla_capa_due_soon_days: Mapped[int] = mapped_column(
        Integer, default=7, server_default=text("7"), nullable=False
    )
    sla_ncr_minor_days: Mapped[int] = mapped_column(
        Integer, default=30, server_default=text("30"), nullable=False
    )
    sla_ncr_major_days: Mapped[int] = mapped_column(
        Integer, default=14, server_default=text("14"), nullable=False
    )
    sla_ncr_critical_days: Mapped[int] = mapped_column(
        Integer, default=7, server_default=text("7"), nullable=False
    )

    # ── Scheduled report digest (periodic email of the QMS summary) ───────────
    report_schedule_enabled: Mapped[bool] = mapped_column(
        Boolean, default=False, server_default=false(), nullable=False
    )
    # One of: daily | weekly | monthly.
    report_schedule_frequency: Mapped[str] = mapped_column(
        String(16), default="weekly", server_default=text("'weekly'"), nullable=False
    )
    # Comma/newline separated recipient email addresses.
    report_schedule_recipients: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Timestamp of the last successful digest send (drives the cadence + acts as
    # the cross-worker dispatch lock via an atomic conditional UPDATE).
    report_schedule_last_sent_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    # ── Executive dashboard: KPI targets (RAG thresholds) ─────────────────────
    kpi_target_open_ncrs: Mapped[float] = mapped_column(
        Numeric(10, 2), default=10, server_default=text("10"), nullable=False
    )
    kpi_target_overdue_capas: Mapped[float] = mapped_column(
        Numeric(10, 2), default=0, server_default=text("0"), nullable=False
    )
    kpi_target_open_findings: Mapped[float] = mapped_column(
        Numeric(10, 2), default=5, server_default=text("5"), nullable=False
    )
    kpi_target_escapes: Mapped[float] = mapped_column(
        Numeric(10, 2), default=3, server_default=text("3"), nullable=False
    )
    kpi_target_capa_on_time: Mapped[float] = mapped_column(
        Numeric(10, 2), default=90, server_default=text("90"), nullable=False
    )
    kpi_target_supplier_quality: Mapped[float] = mapped_column(
        Numeric(10, 2), default=95, server_default=text("95"), nullable=False
    )
    kpi_target_supplier_otd: Mapped[float] = mapped_column(
        Numeric(10, 2), default=95, server_default=text("95"), nullable=False
    )

    # ── Cost of Quality: per-event unit costs (used to convert COQ counts to $) ─
    coq_cost_ncr: Mapped[float] = mapped_column(
        Numeric(12, 2), default=500, server_default=text("500"), nullable=False
    )
    coq_cost_complaint: Mapped[float] = mapped_column(
        Numeric(12, 2), default=2000, server_default=text("2000"), nullable=False
    )
    coq_cost_inspection: Mapped[float] = mapped_column(
        Numeric(12, 2), default=75, server_default=text("75"), nullable=False
    )
    coq_cost_audit: Mapped[float] = mapped_column(
        Numeric(12, 2), default=1500, server_default=text("1500"), nullable=False
    )
    coq_cost_capa: Mapped[float] = mapped_column(
        Numeric(12, 2), default=1200, server_default=text("1200"), nullable=False
    )
