"""Scheduled report digest — a periodic email summary of the QMS.

Builds a plain-text digest from the dashboard KPIs and emails it to the
configured recipients. Cadence (daily/weekly/monthly) and recipients are
admin-configurable on :class:`OrgSettings`.

Cross-worker safety: the scheduled path claims the send with a single atomic
conditional UPDATE of ``report_schedule_last_sent_at`` — only the worker whose
UPDATE affects a row proceeds to send, so the digest goes out at most once per
period even with multiple web workers all ticking.
"""

from __future__ import annotations

import logging
import re
from datetime import UTC, date, datetime, timedelta

from sqlalchemy import or_, select, update
from sqlalchemy.orm import Session

from app.core.config import settings as app_settings
from app.models.calibration import Equipment
from app.models.capa import Capa
from app.models.nonconformance import NcSeverity, Nonconformance
from app.models.settings import OrgSettings
from app.services import delivery, kpi
from app.services.sla import _CAPA_OPEN, _NCR_OPEN, _basis_date

logger = logging.getLogger("app.notifications")

# Most overdue items to spell out individually in the digest before summarising.
_OVERDUE_DETAIL_LIMIT = 12

# Frequency -> minimum days between sends.
_FREQUENCY_DAYS = {"daily": 1, "weekly": 7, "monthly": 30}
# Small grace so a send that lands a little early (scheduler jitter) still fires
# on the intended day rather than slipping a whole period.
_GRACE = timedelta(hours=1)

_EMAIL_SPLIT_RE = re.compile(r"[,\n;]+")
_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


def _now(now: datetime | None) -> datetime:
    return now or datetime.now(UTC)


def _as_utc(dt: datetime) -> datetime:
    """Treat a naive timestamp (e.g. read back from SQLite) as UTC."""
    return dt if dt.tzinfo is not None else dt.replace(tzinfo=UTC)


def _get_settings(db: Session) -> OrgSettings | None:
    org = db.get(OrgSettings, 1)
    if org is None:
        org = db.query(OrgSettings).order_by(OrgSettings.id.asc()).first()
    return org


def parse_recipients(raw: str | None) -> list[str]:
    """Split a free-text recipient string into deduped, valid email addresses."""
    if not raw:
        return []
    seen: set[str] = set()
    out: list[str] = []
    for part in _EMAIL_SPLIT_RE.split(raw):
        email = part.strip()
        if not email or not _EMAIL_RE.match(email):
            continue
        key = email.lower()
        if key in seen:
            continue
        seen.add(key)
        out.append(email)
    return out


def overdue_items(db: Session, *, now: datetime | None = None) -> list[dict]:
    """Concrete overdue records driving today's SLA signals, most overdue first.

    Each entry is ``{"label": str, "days": int}`` where ``days`` is how far past
    the due date / SLA window the record sits. Covers overdue CAPAs (by due
    date), NCRs (by per-severity SLA window) and calibration (by next-due date).
    Read-only — this never escalates or claims, unlike the SLA sweep.
    """
    org = _get_settings(db)
    today = _now(now).date()
    items: list[dict] = []

    def _days(due: date | None) -> int | None:
        return (today - due).days if due is not None and due < today else None

    capas = (
        db.execute(select(Capa).where(Capa.is_deleted.is_(False), Capa.status.in_(_CAPA_OPEN)))
        .scalars()
        .all()
    )
    for capa in capas:
        days = _days(_basis_date(capa.due_date))
        if days is not None:
            items.append({"label": f"CAPA {capa.capa_number} — {capa.title}", "days": days})

    sla_windows = {
        NcSeverity.MINOR: org.sla_ncr_minor_days if org else 30,
        NcSeverity.MAJOR: org.sla_ncr_major_days if org else 14,
        NcSeverity.CRITICAL: org.sla_ncr_critical_days if org else 7,
    }
    ncrs = (
        db.execute(
            select(Nonconformance).where(
                Nonconformance.is_deleted.is_(False),
                Nonconformance.status.in_(_NCR_OPEN),
            )
        )
        .scalars()
        .all()
    )
    for ncr in ncrs:
        window = sla_windows.get(ncr.severity)
        basis = _basis_date(ncr.detected_at) or _basis_date(ncr.created_at)
        if window is None or basis is None:
            continue
        age = (today - basis).days
        if age > window:
            items.append(
                {"label": f"NCR {ncr.ncr_number} — {ncr.title}", "days": age - window}
            )

    equipment = (
        db.execute(
            select(Equipment).where(
                Equipment.is_deleted.is_(False),
                Equipment.next_due_date.is_not(None),
                Equipment.next_due_date < today,
            )
        )
        .scalars()
        .all()
    )
    for eq in equipment:
        days = _days(eq.next_due_date)
        if days is not None:
            items.append({"label": f"Calibration {eq.asset_tag} — {eq.name}", "days": days})

    items.sort(key=lambda it: it["days"], reverse=True)
    return items


def build_digest(db: Session, *, now: datetime | None = None) -> tuple[str, str]:
    """Return ``(subject, body)`` for the current QMS digest."""
    org = _get_settings(db)
    org_name = (org.organization_name if org else None) or app_settings.PROJECT_NAME
    k = kpi.dashboard_kpis(db)
    stamp = _now(now).strftime("%Y-%m-%d")

    lines = [
        f"{org_name} — Quality digest for {stamp}",
        "",
        "Open items",
        f"  • Open NCRs:            {k.get('open_ncrs', 0)}",
        f"  • Open CAPAs:           {k.get('open_capas', 0)}",
        f"  • Overdue CAPAs:        {k.get('overdue_capas', 0)}",
        f"  • Open audits:          {k.get('open_audits', 0)}",
        f"  • Open complaints:      {k.get('open_complaints', 0)}",
        "",
        "Calibration",
        f"  • Due (next 30 days):   {k.get('calibration_due', 0)}",
        f"  • Overdue:              {k.get('calibration_overdue', 0)}",
        "",
        "Suppliers",
        f"  • Avg quality rating:   {k.get('supplier_avg_rating', 0)}",
    ]

    overdue = overdue_items(db, now=now)
    if overdue:
        lines += ["", f"Overdue & SLA breaches ({len(overdue)})"]
        for item in overdue[:_OVERDUE_DETAIL_LIMIT]:
            days = item["days"]
            lines.append(f"  • {item['label']} ({days} day{'s' if days != 1 else ''} overdue)")
        remaining = len(overdue) - _OVERDUE_DETAIL_LIMIT
        if remaining > 0:
            lines.append(f"  • …and {remaining} more")

    base_url = app_settings.APP_BASE_URL.strip().rstrip("/")
    if base_url:
        lines += ["", f"Open the dashboard: {base_url}/dashboard"]
    lines += ["", "— Sentinel QMS automated report"]

    subject = f"{org_name} quality digest — {stamp}"
    return subject, "\n".join(lines)


def _build_pdf_attachment(db: Session, now: datetime | None) -> list[tuple[bytes, str, str]]:
    """Build the digest PDF attachment list; never fail the email over a PDF."""
    try:
        # Imported lazily so the digest text path has no hard PDF dependency.
        from app.services import pdf

        data = pdf.render_digest_pdf(db, now=now)
        stamp = _now(now).strftime("%Y-%m-%d")
        return [(data, f"quality-digest-{stamp}.pdf", "application/pdf")]
    except Exception:  # noqa: BLE001 — attachment is best-effort
        logger.warning("digest PDF generation failed; sending text-only", exc_info=True)
        return []


def _send_to_recipients(
    db: Session,
    recipients: list[str],
    subject: str,
    body: str,
    attachments: list[tuple[bytes, str, str]] | None = None,
) -> tuple[int, list[str]]:
    """Email the digest to each recipient. Returns ``(sent_count, errors)``."""
    cfg = delivery.resolve_channels(db)
    if not cfg.smtp_host:
        return 0, ["SMTP is not configured on the server"]
    sent = 0
    errors: list[str] = []
    for email in recipients:
        ok, detail = delivery.send_email(cfg, email, subject, body, None, attachments)
        if ok:
            sent += 1
        else:
            errors.append(f"{email}: {detail}")
    return sent, errors


def digest_due(org: OrgSettings, now: datetime) -> bool:
    """True when an enabled schedule is due for its next send."""
    if not org.report_schedule_enabled:
        return False
    if not parse_recipients(org.report_schedule_recipients):
        return False
    last = org.report_schedule_last_sent_at
    if last is None:
        return True
    interval = _FREQUENCY_DAYS.get(org.report_schedule_frequency, 7)
    return _as_utc(now) - _as_utc(last) >= timedelta(days=interval) - _GRACE


def send_digest_now(
    db: Session, *, recipients: list[str] | None = None, now: datetime | None = None
) -> dict:
    """Send the digest immediately (manual trigger). Bypasses the cadence check."""
    org = _get_settings(db)
    if org is None:
        return {"ok": False, "sent": 0, "detail": "No organization settings found"}
    targets = (
        recipients if recipients is not None else parse_recipients(org.report_schedule_recipients)
    )
    if not targets:
        return {"ok": False, "sent": 0, "detail": "No valid recipients configured"}

    subject, body = build_digest(db, now=now)
    attachments = _build_pdf_attachment(db, now)
    sent, errors = _send_to_recipients(db, targets, subject, body, attachments)
    if sent:
        org.report_schedule_last_sent_at = _now(now)
        db.commit()
    detail = f"Sent to {sent} recipient{'s' if sent != 1 else ''}."
    if errors:
        detail += " Errors: " + "; ".join(errors)
    return {"ok": sent > 0, "sent": sent, "detail": detail, "errors": errors}


def maybe_send_scheduled(db: Session, *, now: datetime | None = None) -> dict:
    """Send the digest iff due, claiming the send atomically across workers."""
    org = _get_settings(db)
    if org is None or not digest_due(org, _now(now)):
        return {"sent": 0, "claimed": False}

    moment = _now(now)
    interval = _FREQUENCY_DAYS.get(org.report_schedule_frequency, 7)
    cutoff = moment - (timedelta(days=interval) - _GRACE)

    # Atomic claim: only the worker whose UPDATE matches a row proceeds. Setting
    # the timestamp up-front makes the digest at-most-once per period.
    result = db.execute(
        update(OrgSettings)
        .where(
            OrgSettings.id == org.id,
            OrgSettings.report_schedule_enabled.is_(True),
            or_(
                OrgSettings.report_schedule_last_sent_at.is_(None),
                OrgSettings.report_schedule_last_sent_at <= cutoff,
            ),
        )
        .values(report_schedule_last_sent_at=moment)
    )
    db.commit()
    if result.rowcount != 1:
        return {"sent": 0, "claimed": False}

    db.refresh(org)
    targets = parse_recipients(org.report_schedule_recipients)
    subject, body = build_digest(db, now=now)
    attachments = _build_pdf_attachment(db, now)
    sent, errors = _send_to_recipients(db, targets, subject, body, attachments)
    if errors:
        logger.warning("report digest send had errors: %s", "; ".join(errors))
    return {"sent": sent, "claimed": True, "errors": errors}
