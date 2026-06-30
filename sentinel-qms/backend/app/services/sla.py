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
from app.models.audit_mgmt import Audit, AuditStatus
from app.models.calibration import Equipment, EquipmentStatus
from app.models.capa import Capa, CapaAction, CapaActionStatus, CapaStatus
from app.models.concession import Concession, ConcessionStatus
from app.models.document import Document, DocumentStatus
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.settings import OrgSettings
from app.models.sla import SlaEscalation
from app.models.supplier import ScarStatus, Supplier, SupplierScar
from app.models.training import TrainingRecord, TrainingStatus
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
# SCAR states still awaiting a supplier response (i.e. not yet closed).
_SCAR_OPEN = (
    ScarStatus.ISSUED,
    ScarStatus.ACKNOWLEDGED,
    ScarStatus.RESPONSE_RECEIVED,
    ScarStatus.VERIFIED,
)

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
        "audit_overdue": 0,
        "calibration_overdue": 0,
        "concession_expired": 0,
        "training_expired": 0,
        "training_expiring_soon": 0,
        "document_review_overdue": 0,
        "scar_response_overdue": 0,
        "supplier_cert_expired": 0,
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

    # ── Audits past their planned date ───────────────────────────────────────
    audit_open = (AuditStatus.PLANNED, AuditStatus.IN_PROGRESS, AuditStatus.REPORTING)
    audits = (
        db.execute(select(Audit).where(Audit.is_deleted.is_(False), Audit.status.in_(audit_open)))
        .scalars()
        .all()
    )
    for audit in audits:
        due = _basis_date(audit.planned_date)
        if due is None or due >= today:
            continue
        if _claim(db, "audit", audit.id, "overdue"):
            days = (today - due).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=audit.lead_auditor_id,
                title=f"Audit {audit.audit_number} is overdue",
                body=(
                    f"{audit.title} — planned {due.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} overdue)."
                ),
                entity_type="audit",
                entity_id=audit.id,
            )
            db.commit()
            summary["audit_overdue"] += 1

    # ── Calibrations past their due date ─────────────────────────────────────
    equipment = (
        db.execute(
            select(Equipment).where(
                Equipment.is_deleted.is_(False),
                Equipment.status == EquipmentStatus.ACTIVE,
                Equipment.next_due_date.is_not(None),
            )
        )
        .scalars()
        .all()
    )
    for eq in equipment:
        due = _basis_date(eq.next_due_date)
        if due is None or due >= today:
            continue
        if _claim(db, "equipment", eq.id, "overdue"):
            days = (today - due).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=None,
                title=f"Calibration overdue: {eq.asset_tag}",
                body=(
                    f"{eq.name} — calibration due {due.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} overdue)."
                ),
                entity_type="equipment",
                entity_id=eq.id,
            )
            db.commit()
            summary["calibration_overdue"] += 1

    # ── Concessions that have passed their expiry ────────────────────────────
    concessions = (
        db.execute(
            select(Concession).where(
                Concession.is_deleted.is_(False),
                Concession.status == ConcessionStatus.APPROVED,
                Concession.expiry_date.is_not(None),
            )
        )
        .scalars()
        .all()
    )
    for con in concessions:
        exp = _basis_date(con.expiry_date)
        if exp is None or exp >= today:
            continue
        if _claim(db, "concession", con.id, "expired"):
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=None,
                title=f"Concession {con.concession_number} has expired",
                body=f"{con.title} — expired {exp.isoformat()}; review or close.",
                entity_type="concession",
                entity_id=con.id,
            )
            db.commit()
            summary["concession_expired"] += 1

    # ── Training records past or nearing expiry (ISO 9001 7.2 competence) ─────
    records = (
        db.execute(
            select(TrainingRecord).where(
                TrainingRecord.expiry_date.is_not(None),
                TrainingRecord.status != TrainingStatus.EXPIRED,
            )
        )
        .scalars()
        .all()
    )
    for rec in records:
        exp = _basis_date(rec.expiry_date)
        if exp is None:
            continue
        person = rec.personnel
        course = rec.course
        who = person.full_name if person is not None else f"personnel {rec.personnel_id}"
        what = course.title if course is not None else f"course {rec.course_id}"
        primary = person.user_id if person is not None else None
        if exp < today:
            if _claim(db, "training", rec.id, "expired"):
                rec.status = TrainingStatus.EXPIRED
                days = (today - exp).days
                _escalate(
                    db,
                    recipient_ids=managers,
                    primary_user_id=primary,
                    title=f"Training expired: {what}",
                    body=(
                        f"{who} — '{what}' expired {exp.isoformat()} "
                        f"({days} day{'s' if days != 1 else ''} ago). Renewal required."
                    ),
                    entity_type="training",
                    entity_id=rec.id,
                )
                db.commit()
                summary["training_expired"] += 1
        elif exp <= due_soon_cutoff and _claim(db, "training", rec.id, "expiring_soon"):
            days = (exp - today).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=primary,
                title=f"Training expiring soon: {what}",
                body=(
                    f"{who} — '{what}' expires {exp.isoformat()} "
                    f"(in {days} day{'s' if days != 1 else ''}). Schedule renewal."
                ),
                entity_type="training",
                entity_id=rec.id,
            )
            db.commit()
            summary["training_expiring_soon"] += 1

    # ── Controlled documents past their periodic-review date (AS9100 7.5) ─────
    docs = (
        db.execute(
            select(Document).where(
                Document.is_deleted.is_(False),
                Document.status == DocumentStatus.APPROVED,
                Document.next_review_date.is_not(None),
            )
        )
        .scalars()
        .all()
    )
    for doc in docs:
        due = _basis_date(doc.next_review_date)
        if due is None or due >= today:
            continue
        if _claim(db, "document", doc.id, "review_overdue"):
            days = (today - due).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=doc.owner_id,
                title=f"Document review overdue: {doc.document_number}",
                body=(
                    f"{doc.title} — periodic review was due {due.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} overdue). Review or re-approve."
                ),
                entity_type="document",
                entity_id=doc.id,
            )
            db.commit()
            summary["document_review_overdue"] += 1

    # ── Supplier SCARs past their response-due date (AS9100 8.4 supplier mgmt) ─
    scars = (
        db.execute(select(SupplierScar).where(SupplierScar.status.in_(_SCAR_OPEN))).scalars().all()
    )
    for scar in scars:
        due = _basis_date(scar.response_due_date)
        if due is None or due >= today:
            continue
        if _claim(db, "supplier_scar", scar.id, "response_overdue"):
            days = (today - due).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=None,
                title=f"SCAR {scar.scar_number} response overdue",
                body=(
                    f"{scar.title} — supplier response was due {due.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} overdue)."
                ),
                entity_type="supplier",
                entity_id=scar.supplier_id,
            )
            db.commit()
            summary["scar_response_overdue"] += 1

    # ── Suppliers whose certification has lapsed (AS9100 8.4 supplier mgmt) ────
    suppliers = (
        db.execute(
            select(Supplier).where(
                Supplier.is_deleted.is_(False),
                Supplier.cert_expiry.is_not(None),
            )
        )
        .scalars()
        .all()
    )
    for supplier in suppliers:
        exp = _basis_date(supplier.cert_expiry)
        if exp is None or exp >= today:
            continue
        if _claim(db, "supplier", supplier.id, "cert_expired"):
            days = (today - exp).days
            _escalate(
                db,
                recipient_ids=managers,
                primary_user_id=None,
                title=f"Supplier certification expired: {supplier.name}",
                body=(
                    f"{supplier.supplier_code} — certification expired {exp.isoformat()} "
                    f"({days} day{'s' if days != 1 else ''} ago). Re-qualify supplier."
                ),
                entity_type="supplier",
                entity_id=supplier.id,
            )
            db.commit()
            summary["supplier_cert_expired"] += 1

    return summary
