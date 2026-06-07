"""SLA escalation sweep — auto-notify on overdue / due-soon NCRs and CAPAs.

Run periodically by the in-process scheduler (and on-demand via the settings
API). For every open record past (or approaching) its SLA window an escalation
is *claimed* in the :class:`SlaEscalation` ledger and a notification is fanned
out. The claim is a per-row SAVEPOINT INSERT guarded by a unique constraint, so
each (record, level) escalates exactly once even across concurrent web workers.

SLA windows are admin-configurable on :class:`OrgSettings`:

* CAPA — ``overdue`` is relative to ``Capa.due_date``; ``due_soon`` fires when
  the due date is within ``sla_capa_due_soon_days``. CAPA *actions* escalate
  ``overdue`` relative to their own ``due_date``.
* NCR — ``overdue`` fires when the record's age (from ``detected_at`` or
  ``created_at``) exceeds the per-severity window
  (``sla_ncr_minor_days`` / ``major`` / ``critical``).
"""

from __future__ import annotations

import logging
from datetime import UTC, date, datetime, timedelta

from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.core.rbac import Role
from app.models.capa import Capa, CapaAction, CapaActionStatus, CapaStatus
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.settings import OrgSettings
from app.models.sla import SlaEscalation
from app.models.user import Notification, User
from app.models.user import Role as RoleModel
from app.schemas.notification import notification_url
from app.services import delivery

logger = logging.getLogger("app.notifications")

# CAPA states still considered "open" (i.e. eligible for escalation).
_CAPA_OPEN = (
    CapaStatus.OPEN,
    CapaStatus.CONTAINMENT,
    CapaStatus.ROOT_CAUSE,
    CapaStatus.ACTION_PLAN,
    CapaStatus.IMPLEMENTATION,
    CapaStatus.VERIFICATION,
)
# CAPA action states still considered open.
_ACTION_OPEN = (CapaActionStatus.OPEN, CapaActionStatus.IN_PROGRESS)
# NCR states that still require attention (and so can breach an SLA).
_NCR_OPEN = (NcStatus.OPEN, NcStatus.UNDER_REVIEW)

# Roles that receive a copy of every escalation in addition to the owner.
_ESCALATION_ROLES = (Role.QUALITY_MANAGER, Role.ADMIN)


def _now(now: datetime | None) -> datetime:
    return now or datetime.now(UTC)


def _get_settings(db: Session) -> OrgSettings | None:
    org = db.get(OrgSettings, 1)
    if org is None:
        org = db.query(OrgSettings).order_by(OrgSettings.id.asc()).first()
    return org


def _claim(db: Session, entity_type: str, entity_id: str | int, level: str) -> bool:
    """Atomically claim an escalation. Returns True iff this caller won the race.

    Uses a SAVEPOINT so a duplicate (unique-constraint) insert rolls back only
    this claim — never the notifications already added in the outer transaction.
    """
    nested = db.begin_nested()
    try:
        db.add(SlaEscalation(entity_type=entity_type, entity_id=str(entity_id), level=level))
        db.flush()
        nested.commit()
        return True
    except IntegrityError:
        nested.rollback()
        return False


def _escalation_user_ids(db: Session) -> list[int]:
    """Active users holding a Quality Manager or Admin role."""
    role_names = [r.value for r in _ESCALATION_ROLES]
    return list(
        db.execute(
            select(User.id)
            .join(User.roles)
            .where(RoleModel.name.in_(role_names), User.is_active.is_(True))
            .distinct()
        )
        .scalars()
        .all()
    )


def _escalate(
    db: Session,
    *,
    recipient_ids: list[int],
    primary_user_id: int | None,
    title: str,
    body: str,
    entity_type: str,
    entity_id: str | int,
) -> None:
    """Create in-app notifications for every recipient and dispatch externally once.

    External delivery (email/Teams/Slack) is fired a single time — email to the
    primary recipient (typically the owner/assignee) — to avoid webhook spam
    when several managers are notified.
    """
    seen: set[int] = set()
    ordered = ([primary_user_id] if primary_user_id else []) + recipient_ids
    for uid in ordered:
        if uid is None or uid in seen:
            continue
        seen.add(uid)
        db.add(
            Notification(
                user_id=uid,
                title=title,
                body=body,
                category="sla",
                entity_type=entity_type,
                entity_id=str(entity_id),
            )
        )
    db.flush()

    primary_email: str | None = None
    if primary_user_id is not None:
        primary = db.get(User, primary_user_id)
        primary_email = primary.email if primary is not None else None

    link = notification_url(entity_type, str(entity_id))
    cfg = delivery.resolve_channels(db)
    delivery.dispatch_notification(
        recipient_email=primary_email,
        title=title,
        body=body,
        link=link,
        cfg=cfg,
    )


def _basis_date(value) -> date | None:
    if value is None:
        return None
    return value.date() if hasattr(value, "date") else value


def run_sla_sweep(db: Session, *, now: datetime | None = None) -> dict:
    """Escalate every open record that has breached (or is approaching) its SLA.

    Idempotent: re-running only escalates records that crossed a new threshold
    since the last run. Returns a summary of how many escalations fired.
    """
    org = _get_settings(db)
    summary = {
        "enabled": bool(org and org.sla_enabled),
        "capa_overdue": 0,
        "capa_due_soon": 0,
        "capa_action_overdue": 0,
        "ncr_overdue": 0,
    }
    if not org or not org.sla_enabled:
        return summary

    today = _now(now).date()
    due_soon_cutoff = today + timedelta(days=max(org.sla_capa_due_soon_days, 0))
    managers = _escalation_user_ids(db)

    # ── CAPAs ────────────────────────────────────────────────────────────────
    capas = (
        db.execute(select(Capa).where(Capa.is_deleted.is_(False), Capa.status.in_(_CAPA_OPEN)))
        .scalars()
        .all()
    )
    for capa in capas:
        due = _basis_date(capa.due_date)
        if due is None:
            continue
        if due < today:
            if _claim(db, "capa", capa.id, "overdue"):
                days = (today - due).days
                _escalate(
                    db,
                    recipient_ids=managers,
                    primary_user_id=capa.owner_id,
                    title=f"CAPA {capa.capa_number} is overdue",
                    body=(
                        f"{capa.title} — due {due.isoformat()} "
                        f"({days} day{'s' if days != 1 else ''} overdue)."
                    ),
                    entity_type="capa",
                    entity_id=capa.id,
                )
                db.commit()
                summary["capa_overdue"] += 1
        elif due <= due_soon_cutoff and _claim(db, "capa", capa.id, "due_soon"):
            days = (due - today).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=capa.owner_id,
                title=f"CAPA {capa.capa_number} is due soon",
                body=(
                    f"{capa.title} — due {due.isoformat()} "
                    f"(in {days} day{'s' if days != 1 else ''})."
                ),
                entity_type="capa",
                entity_id=capa.id,
            )
            db.commit()
            summary["capa_due_soon"] += 1

    # ── CAPA actions ───────────────────────────────────────────────────────--
    actions = (
        db.execute(select(CapaAction).where(CapaAction.status.in_(_ACTION_OPEN))).scalars().all()
    )
    for action in actions:
        due = _basis_date(action.due_date)
        if due is None or due >= today:
            continue
        if _claim(db, "capa_action", action.id, "overdue"):
            capa = db.get(Capa, action.capa_id)
            if capa is None or capa.is_deleted:
                db.commit()
                continue
            days = (today - due).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=action.owner_id or capa.owner_id,
                title=f"CAPA action overdue ({capa.capa_number})",
                body=(
                    f"{action.description} — due {due.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} overdue)."
                ),
                entity_type="capa",
                entity_id=capa.id,
            )
            db.commit()
            summary["capa_action_overdue"] += 1

    # ── NCRs (severity-driven SLA from detection/creation) ─────────────────---
    sla_by_severity = {
        NcSeverity.MINOR: org.sla_ncr_minor_days,
        NcSeverity.MAJOR: org.sla_ncr_major_days,
        NcSeverity.CRITICAL: org.sla_ncr_critical_days,
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
        window = sla_by_severity.get(ncr.severity)
        if window is None:
            continue
        basis = _basis_date(ncr.detected_at) or _basis_date(ncr.created_at)
        if basis is None:
            continue
        age = (today - basis).days
        if age <= window:
            continue
        if _claim(db, "nonconformance", ncr.id, "overdue"):
            sev = ncr.severity.value if hasattr(ncr.severity, "value") else str(ncr.severity)
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=ncr.assigned_to,
                title=f"NCR {ncr.ncr_number} has breached its SLA",
                body=(f"{ncr.title} — {sev} NCR open {age} days (SLA {window} days)."),
                entity_type="nonconformance",
                entity_id=ncr.id,
            )
            db.commit()
            summary["ncr_overdue"] += 1

    return summary
