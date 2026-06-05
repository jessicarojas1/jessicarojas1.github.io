"""Dashboard KPI aggregation queries."""
from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.audit_mgmt import AuditFinding, FindingStatus
from app.models.calibration import Equipment, EquipmentStatus
from app.models.capa import Capa, CapaStatus
from app.models.complaint import Complaint, ComplaintStatus
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.supplier import Supplier, SupplierRating, SupplierStatus


def _today() -> date:
    return date.today()


def open_ncr_metrics(db: Session) -> dict:
    open_states = [NcStatus.OPEN, NcStatus.UNDER_REVIEW, NcStatus.DISPOSITIONED]
    base = select(func.count()).select_from(Nonconformance).where(
        Nonconformance.is_deleted.is_(False)
    )
    total_open = db.execute(base.where(Nonconformance.status.in_(open_states))).scalar_one()
    critical_open = db.execute(
        base.where(
            Nonconformance.status.in_(open_states),
            Nonconformance.severity == NcSeverity.CRITICAL,
        )
    ).scalar_one()
    by_severity = {
        sev.value: db.execute(
            base.where(
                Nonconformance.status.in_(open_states), Nonconformance.severity == sev
            )
        ).scalar_one()
        for sev in NcSeverity
    }
    return {
        "open_total": int(total_open),
        "critical_open": int(critical_open),
        "by_severity": by_severity,
    }


def capa_metrics(db: Session) -> dict:
    today = _today()
    open_states = [
        CapaStatus.OPEN,
        CapaStatus.CONTAINMENT,
        CapaStatus.ROOT_CAUSE,
        CapaStatus.ACTION_PLAN,
        CapaStatus.IMPLEMENTATION,
        CapaStatus.VERIFICATION,
    ]
    base = select(func.count()).select_from(Capa).where(Capa.is_deleted.is_(False))
    total_open = db.execute(base.where(Capa.status.in_(open_states))).scalar_one()
    overdue = db.execute(
        base.where(
            Capa.status.in_(open_states),
            Capa.due_date.is_not(None),
            Capa.due_date < today,
        )
    ).scalar_one()
    awaiting_verification = db.execute(
        base.where(Capa.status == CapaStatus.VERIFICATION)
    ).scalar_one()
    return {
        "open_total": int(total_open),
        "overdue": int(overdue),
        "awaiting_effectiveness_verification": int(awaiting_verification),
    }


def calibration_metrics(db: Session, *, soon_days: int = 30) -> dict:
    today = _today()
    soon = today + timedelta(days=soon_days)
    base = select(func.count()).select_from(Equipment).where(
        Equipment.is_deleted.is_(False),
        Equipment.status == EquipmentStatus.ACTIVE,
    )
    overdue = db.execute(
        base.where(Equipment.next_due_date.is_not(None), Equipment.next_due_date < today)
    ).scalar_one()
    due_soon = db.execute(
        base.where(
            Equipment.next_due_date.is_not(None),
            Equipment.next_due_date >= today,
            Equipment.next_due_date <= soon,
        )
    ).scalar_one()
    active = db.execute(base).scalar_one()
    return {
        "active_equipment": int(active),
        "overdue": int(overdue),
        "due_within_days": soon_days,
        "due_soon": int(due_soon),
    }


def audit_finding_metrics(db: Session) -> dict:
    base = select(func.count()).select_from(AuditFinding)
    open_findings = db.execute(
        base.where(
            AuditFinding.status.in_([FindingStatus.OPEN, FindingStatus.RESPONSE_SUBMITTED])
        )
    ).scalar_one()
    by_type: dict[str, int] = {}
    rows = db.execute(
        select(AuditFinding.finding_type, func.count())
        .where(AuditFinding.status != FindingStatus.CLOSED)
        .group_by(AuditFinding.finding_type)
    ).all()
    for ftype, count in rows:
        by_type[ftype.value if hasattr(ftype, "value") else str(ftype)] = int(count)
    return {"open_findings": int(open_findings), "open_by_type": by_type}


def supplier_metrics(db: Session) -> dict:
    base = select(func.count()).select_from(Supplier).where(Supplier.is_deleted.is_(False))
    approved = db.execute(
        base.where(Supplier.status == SupplierStatus.APPROVED)
    ).scalar_one()
    disqualified = db.execute(
        base.where(Supplier.status == SupplierStatus.DISQUALIFIED)
    ).scalar_one()
    avg_quality = db.execute(
        select(func.avg(SupplierRating.quality_score))
    ).scalar()
    avg_otd = db.execute(select(func.avg(SupplierRating.on_time_delivery))).scalar()
    return {
        "approved_suppliers": int(approved),
        "disqualified_suppliers": int(disqualified),
        "avg_quality_score": round(float(avg_quality), 2) if avg_quality is not None else None,
        "avg_on_time_delivery": round(float(avg_otd), 2) if avg_otd is not None else None,
    }


def complaint_metrics(db: Session) -> dict:
    base = select(func.count()).select_from(Complaint).where(Complaint.is_deleted.is_(False))
    open_states = [
        ComplaintStatus.RECEIVED,
        ComplaintStatus.UNDER_INVESTIGATION,
        ComplaintStatus.AWAITING_CUSTOMER,
    ]
    open_total = db.execute(base.where(Complaint.status.in_(open_states))).scalar_one()
    return {"open_total": int(open_total)}


def dashboard_summary(db: Session) -> dict:
    """Single aggregate consumed by the dashboard router."""
    return {
        "nonconformances": open_ncr_metrics(db),
        "capa": capa_metrics(db),
        "calibration": calibration_metrics(db),
        "audit_findings": audit_finding_metrics(db),
        "suppliers": supplier_metrics(db),
        "complaints": complaint_metrics(db),
        "generated_at": _today().isoformat(),
    }
