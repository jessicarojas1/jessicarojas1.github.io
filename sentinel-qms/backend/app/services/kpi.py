"""Dashboard KPI aggregation queries."""
from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.audit_mgmt import Audit, AuditFinding, AuditStatus, FindingStatus
from app.models.calibration import Equipment, EquipmentStatus
from app.models.capa import Capa, CapaStatus
from app.models.change import ChangeOrder, ChangeStatus
from app.models.complaint import Complaint, ComplaintStatus
from app.models.inspection import Inspection, InspectionResult
from app.models.mgmt_review import ManagementReview, ReviewStatus
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.risk import Risk, RiskStatus
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


_NCR_OPEN = [NcStatus.OPEN, NcStatus.UNDER_REVIEW, NcStatus.DISPOSITIONED]
_CAPA_OPEN = [
    CapaStatus.OPEN, CapaStatus.CONTAINMENT, CapaStatus.ROOT_CAUSE,
    CapaStatus.ACTION_PLAN, CapaStatus.IMPLEMENTATION, CapaStatus.VERIFICATION,
]
_AUDIT_OPEN = [AuditStatus.PLANNED, AuditStatus.IN_PROGRESS, AuditStatus.REPORTING]
_COMPLAINT_OPEN = [
    ComplaintStatus.RECEIVED, ComplaintStatus.UNDER_INVESTIGATION,
    ComplaintStatus.AWAITING_CUSTOMER,
]


def _month_key(value) -> str | None:
    if value is None:
        return None
    return f"{value.year:04d}-{value.month:02d}"


def _month_series(n: int = 6) -> list[str]:
    today = _today()
    y, m = today.year, today.month
    out: list[str] = []
    for _ in range(n):
        out.append(f"{y:04d}-{m:02d}")
        m -= 1
        if m == 0:
            m, y = 12, y - 1
    return list(reversed(out))


def _count(db: Session, model, *conds) -> int:
    stmt = select(func.count()).select_from(model)
    if hasattr(model, "is_deleted"):
        stmt = stmt.where(model.is_deleted.is_(False))
    for c in conds:
        stmt = stmt.where(c)
    return int(db.execute(stmt).scalar_one())


def dashboard_kpis(db: Session) -> dict:
    """Rich KPI payload (cards + charts) for the quality dashboard."""
    today = _today()
    soon = today + timedelta(days=30)

    cal_overdue = _count(
        db, Equipment, Equipment.status == EquipmentStatus.ACTIVE,
        Equipment.next_due_date.is_not(None), Equipment.next_due_date < today,
    )
    cal_due = _count(
        db, Equipment, Equipment.status == EquipmentStatus.ACTIVE,
        Equipment.next_due_date.is_not(None),
        Equipment.next_due_date >= today, Equipment.next_due_date <= soon,
    )
    active_equip = _count(db, Equipment, Equipment.status == EquipmentStatus.ACTIVE)
    avg_rating = db.execute(select(func.avg(SupplierRating.quality_score))).scalar()

    kpis = {
        "open_ncrs": _count(db, Nonconformance, Nonconformance.status.in_(_NCR_OPEN)),
        "open_capas": _count(db, Capa, Capa.status.in_(_CAPA_OPEN)),
        "overdue_capas": _count(
            db, Capa, Capa.status.in_(_CAPA_OPEN),
            Capa.due_date.is_not(None), Capa.due_date < today,
        ),
        "calibration_due": cal_due,
        "calibration_overdue": cal_overdue,
        "open_audits": _count(db, Audit, Audit.status.in_(_AUDIT_OPEN)),
        "supplier_avg_rating": round(float(avg_rating), 1) if avg_rating is not None else 0.0,
        "open_complaints": _count(db, Complaint, Complaint.status.in_(_COMPLAINT_OPEN)),
    }

    # NCR opened vs closed by month
    months = _month_series(6)
    window = set(months)
    opened = {k: 0 for k in months}
    closed = {k: 0 for k in months}
    for row in db.execute(
        select(Nonconformance).where(Nonconformance.is_deleted.is_(False))
    ).scalars().all():
        ok = _month_key(getattr(row, "created_at", None))
        if ok in window:
            opened[ok] += 1
        if row.status == NcStatus.CLOSED:
            ck = _month_key(getattr(row, "closed_at", None) or getattr(row, "updated_at", None))
            if ck in window:
                closed[ck] += 1
    ncr_trend = [{"month": k, "opened": opened[k], "closed": closed[k]} for k in months]

    # CAPA aging (open CAPAs by age in days)
    aging = {"0-30": 0, "31-60": 0, "61-90": 0, "90+": 0}
    for row in db.execute(
        select(Capa).where(Capa.is_deleted.is_(False), Capa.status.in_(_CAPA_OPEN))
    ).scalars().all():
        created = getattr(row, "created_at", None)
        if created is None:
            continue
        created_date = created.date() if hasattr(created, "date") else created
        days = (today - created_date).days
        if days <= 30:
            aging["0-30"] += 1
        elif days <= 60:
            aging["31-60"] += 1
        elif days <= 90:
            aging["61-90"] += 1
        else:
            aging["90+"] += 1
    capa_aging = [{"bucket": b, "count": c} for b, c in aging.items()]

    calibration_status = [
        {"name": "OK", "value": max(active_equip - cal_due - cal_overdue, 0)},
        {"name": "Due Soon", "value": cal_due},
        {"name": "Overdue", "value": cal_overdue},
    ]

    sp_rows = db.execute(
        select(
            Supplier.name,
            func.avg(SupplierRating.quality_score),
            func.avg(SupplierRating.on_time_delivery),
        )
        .join(SupplierRating, SupplierRating.supplier_id == Supplier.id)
        .where(Supplier.is_deleted.is_(False))
        .group_by(Supplier.name)
    ).all()
    supplier_performance = [
        {
            "name": name,
            "rating": round(float(q), 1) if q is not None else 0.0,
            "otd": round(float(o), 1) if o is not None else 0.0,
        }
        for name, q, o in sp_rows
    ][:8]

    fc_rows = db.execute(
        select(AuditFinding.clause_reference, func.count())
        .where(AuditFinding.status != FindingStatus.CLOSED)
        .group_by(AuditFinding.clause_reference)
    ).all()
    findings_by_clause = [
        {"clause": clause or "Unspecified", "count": int(n)} for clause, n in fc_rows
    ][:10]

    return {
        "kpis": kpis,
        "ncr_trend": ncr_trend,
        "capa_aging": capa_aging,
        "calibration_status": calibration_status,
        "supplier_performance": supplier_performance,
        "findings_by_clause": findings_by_clause,
    }


_CHANGE_OPEN = [
    ChangeStatus.DRAFT, ChangeStatus.SUBMITTED,
    ChangeStatus.UNDER_REVIEW, ChangeStatus.APPROVED,
]
_RISK_OPEN = [
    RiskStatus.IDENTIFIED, RiskStatus.ASSESSED, RiskStatus.TREATMENT_PLANNED,
    RiskStatus.MITIGATING, RiskStatus.MONITORING,
]
_REVIEW_OPEN = [ReviewStatus.SCHEDULED, ReviewStatus.IN_PROGRESS]


def my_open_items(db: Session, user_id: int, *, limit: int = 60) -> list[dict]:
    """Records assigned to / owned by the given user that are still open."""
    today = _today()
    items: list[dict] = []

    def add(kind, rec, number_attr, title, due, url):
        due_d = due
        items.append(
            {
                "type": kind,
                "id": rec.id,
                "number": getattr(rec, number_attr, None) or f"#{rec.id}",
                "title": title or "—",
                "status": (rec.status.value if hasattr(rec.status, "value") else str(rec.status)),
                "due_date": due_d.isoformat() if due_d else None,
                "overdue": bool(due_d and due_d < today),
                "url": url,
            }
        )

    for r in db.execute(
        select(Nonconformance).where(
            Nonconformance.is_deleted.is_(False),
            Nonconformance.assigned_to == user_id,
            Nonconformance.status.in_(_NCR_OPEN),
        )
    ).scalars().all():
        add("Nonconformance", r, "ncr_number", r.title, None, f"/nonconformances/{r.id}")

    for r in db.execute(
        select(Capa).where(
            Capa.is_deleted.is_(False), Capa.owner_id == user_id,
            Capa.status.in_(_CAPA_OPEN),
        )
    ).scalars().all():
        add("CAPA", r, "capa_number", r.title, getattr(r, "due_date", None), f"/capa/{r.id}")

    for r in db.execute(
        select(Audit).where(
            Audit.is_deleted.is_(False), Audit.lead_auditor_id == user_id,
            Audit.status.in_(_AUDIT_OPEN),
        )
    ).scalars().all():
        add("Audit", r, "audit_number", r.title, getattr(r, "planned_date", None), f"/audits/{r.id}")

    for r in db.execute(
        select(ChangeOrder).where(
            ChangeOrder.is_deleted.is_(False), ChangeOrder.owner_id == user_id,
            ChangeOrder.status.in_(_CHANGE_OPEN),
        )
    ).scalars().all():
        add("Change", r, "change_number", r.title, getattr(r, "target_date", None), f"/changes/{r.id}")

    for r in db.execute(
        select(Risk).where(
            Risk.is_deleted.is_(False), Risk.owner_id == user_id,
            Risk.status.in_(_RISK_OPEN),
        )
    ).scalars().all():
        add("Risk", r, "risk_number", r.title, getattr(r, "review_date", None), f"/risks/{r.id}")

    for r in db.execute(
        select(Complaint).where(
            Complaint.is_deleted.is_(False), Complaint.assigned_to == user_id,
            Complaint.status.in_(_COMPLAINT_OPEN),
        )
    ).scalars().all():
        add("Complaint", r, "complaint_number", r.title, getattr(r, "due_date", None), f"/complaints/{r.id}")

    for r in db.execute(
        select(Inspection).where(
            Inspection.is_deleted.is_(False), Inspection.inspector_id == user_id,
            Inspection.result == InspectionResult.PENDING,
        )
    ).scalars().all():
        add("Inspection", r, "inspection_number", getattr(r, "part_number", None), None, f"/inspections/{r.id}")

    for r in db.execute(
        select(ManagementReview).where(
            ManagementReview.is_deleted.is_(False),
            ManagementReview.chairperson_id == user_id,
            ManagementReview.status.in_(_REVIEW_OPEN),
        )
    ).scalars().all():
        add("Management Review", r, "review_number", r.title, getattr(r, "due_date", None), f"/mgmt-reviews/{r.id}")

    # Overdue first, then soonest due, then those without a due date.
    items.sort(key=lambda i: (not i["overdue"], i["due_date"] or "9999-99-99"))
    return items[:limit]
