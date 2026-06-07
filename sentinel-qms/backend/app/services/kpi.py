"""Dashboard KPI aggregation queries."""

from __future__ import annotations

from datetime import date, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.models.audit_mgmt import Audit, AuditFinding, AuditStatus, FindingStatus, FindingType
from app.models.calibration import Equipment, EquipmentStatus
from app.models.capa import Capa, CapaStatus
from app.models.change import ChangeOrder, ChangeStatus
from app.models.complaint import Complaint, ComplaintStatus
from app.models.inspection import Inspection, InspectionResult
from app.models.mgmt_review import (
    ActionItem,
    ActionItemStatus,
    ManagementReview,
    ReviewStatus,
)
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.risk import Risk, RiskStatus
from app.models.settings import OrgSettings
from app.models.supplier import Supplier, SupplierRating, SupplierStatus


def _today() -> date:
    return date.today()


def open_ncr_metrics(db: Session) -> dict:
    open_states = [NcStatus.OPEN, NcStatus.UNDER_REVIEW, NcStatus.DISPOSITIONED]
    base = (
        select(func.count()).select_from(Nonconformance).where(Nonconformance.is_deleted.is_(False))
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
            base.where(Nonconformance.status.in_(open_states), Nonconformance.severity == sev)
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
    base = (
        select(func.count())
        .select_from(Equipment)
        .where(
            Equipment.is_deleted.is_(False),
            Equipment.status == EquipmentStatus.ACTIVE,
        )
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
        base.where(AuditFinding.status.in_([FindingStatus.OPEN, FindingStatus.RESPONSE_SUBMITTED]))
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
    approved = db.execute(base.where(Supplier.status == SupplierStatus.APPROVED)).scalar_one()
    disqualified = db.execute(
        base.where(Supplier.status == SupplierStatus.DISQUALIFIED)
    ).scalar_one()
    avg_quality = db.execute(select(func.avg(SupplierRating.quality_score))).scalar()
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
    CapaStatus.OPEN,
    CapaStatus.CONTAINMENT,
    CapaStatus.ROOT_CAUSE,
    CapaStatus.ACTION_PLAN,
    CapaStatus.IMPLEMENTATION,
    CapaStatus.VERIFICATION,
]
_AUDIT_OPEN = [AuditStatus.PLANNED, AuditStatus.IN_PROGRESS, AuditStatus.REPORTING]
_COMPLAINT_OPEN = [
    ComplaintStatus.RECEIVED,
    ComplaintStatus.UNDER_INVESTIGATION,
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
        db,
        Equipment,
        Equipment.status == EquipmentStatus.ACTIVE,
        Equipment.next_due_date.is_not(None),
        Equipment.next_due_date < today,
    )
    cal_due = _count(
        db,
        Equipment,
        Equipment.status == EquipmentStatus.ACTIVE,
        Equipment.next_due_date.is_not(None),
        Equipment.next_due_date >= today,
        Equipment.next_due_date <= soon,
    )
    active_equip = _count(db, Equipment, Equipment.status == EquipmentStatus.ACTIVE)
    avg_rating = db.execute(select(func.avg(SupplierRating.quality_score))).scalar()

    kpis = {
        "open_ncrs": _count(db, Nonconformance, Nonconformance.status.in_(_NCR_OPEN)),
        "open_capas": _count(db, Capa, Capa.status.in_(_CAPA_OPEN)),
        "overdue_capas": _count(
            db,
            Capa,
            Capa.status.in_(_CAPA_OPEN),
            Capa.due_date.is_not(None),
            Capa.due_date < today,
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
    opened = dict.fromkeys(months, 0)
    closed = dict.fromkeys(months, 0)
    for row in (
        db.execute(select(Nonconformance).where(Nonconformance.is_deleted.is_(False)))
        .scalars()
        .all()
    ):
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
    for row in (
        db.execute(select(Capa).where(Capa.is_deleted.is_(False), Capa.status.in_(_CAPA_OPEN)))
        .scalars()
        .all()
    ):
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


# AS9100D / ISO 9001 top-level clauses, used by the findings heatmap.
_AS9100_CLAUSES = {
    "4": "Context of the Organization",
    "5": "Leadership",
    "6": "Planning",
    "7": "Support",
    "8": "Operation",
    "9": "Performance Evaluation",
    "10": "Improvement",
}


def _as_date(value):
    if value is None:
        return None
    return value.date() if hasattr(value, "date") else value


def _exec_kpi(key, label, value, *, unit="", target=None, direction="lower_better"):
    """Build an executive KPI with a RAG status computed against its target.

    ``direction`` is ``lower_better`` (e.g. open NCRs) or ``higher_better``
    (e.g. on-time closure %). Targets are sensible defaults; they are intended
    to become configurable later.
    """
    status = "neutral"
    if target is not None:
        if direction == "lower_better":
            status = "good" if value <= target else "warn" if value <= target * 1.5 else "bad"
        else:
            status = "good" if value >= target else "warn" if value >= target * 0.9 else "bad"
    return {
        "key": key,
        "label": label,
        "value": round(float(value), 1),
        "unit": unit,
        "target": target,
        "direction": direction,
        "status": status,
    }


def executive_dashboard(db: Session) -> dict:
    """Executive-altitude posture: KPIs vs target, event-based Cost of Quality,
    an AS9100 clause findings heatmap, and an upcoming compliance calendar.

    The Cost of Quality is *event-based* (counts of quality events bucketed into
    the four classic COQ categories) — a recognized proxy when monetary cost
    data is not captured. Monetary weighting can be layered on later.
    """
    today = _today()
    since = today - timedelta(days=90)
    months = _month_series(6)
    window = set(months)

    settings = db.get(OrgSettings, 1) or db.query(OrgSettings).order_by(OrgSettings.id).first()

    def _cfg(attr: str, default: float) -> float:
        val = getattr(settings, attr, None) if settings is not None else None
        return float(val) if val is not None else float(default)

    def _counts_by_month(model):
        counts = dict.fromkeys(months, 0)
        stmt = select(model)
        if hasattr(model, "is_deleted"):
            stmt = stmt.where(model.is_deleted.is_(False))
        for row in db.execute(stmt).scalars().all():
            mk = _month_key(getattr(row, "created_at", None))
            if mk in window:
                counts[mk] += 1
        return counts

    prevention = _counts_by_month(Capa)
    inspections = _counts_by_month(Inspection)
    audits = _counts_by_month(Audit)
    internal = _counts_by_month(Nonconformance)
    external = _counts_by_month(Complaint)

    # Per-event unit costs convert event counts into a dollar Cost of Quality.
    c_capa = _cfg("coq_cost_capa", 1200)
    c_insp = _cfg("coq_cost_inspection", 75)
    c_audit = _cfg("coq_cost_audit", 1500)
    c_ncr = _cfg("coq_cost_ncr", 500)
    c_complaint = _cfg("coq_cost_complaint", 2000)

    def _coq_row(m: str) -> dict:
        appraisal = inspections[m] + audits[m]
        return {
            "month": m,
            "prevention": prevention[m],
            "appraisal": appraisal,
            "internal_failure": internal[m],
            "external_failure": external[m],
            "prevention_cost": round(prevention[m] * c_capa, 2),
            "appraisal_cost": round(inspections[m] * c_insp + audits[m] * c_audit, 2),
            "internal_failure_cost": round(internal[m] * c_ncr, 2),
            "external_failure_cost": round(external[m] * c_complaint, 2),
        }

    coq_trend = [_coq_row(m) for m in months]
    last = months[-1]
    _cur = _coq_row(last)
    coq_current = {
        "prevention": _cur["prevention"],
        "appraisal": _cur["appraisal"],
        "internal_failure": _cur["internal_failure"],
        "external_failure": _cur["external_failure"],
        "total": _cur["prevention"]
        + _cur["appraisal"]
        + _cur["internal_failure"]
        + _cur["external_failure"],
        "prevention_cost": _cur["prevention_cost"],
        "appraisal_cost": _cur["appraisal_cost"],
        "internal_failure_cost": _cur["internal_failure_cost"],
        "external_failure_cost": _cur["external_failure_cost"],
        "total_cost": round(
            _cur["prevention_cost"]
            + _cur["appraisal_cost"]
            + _cur["internal_failure_cost"]
            + _cur["external_failure_cost"],
            2,
        ),
    }

    # ---- AS9100 clause findings heatmap (open findings by clause × type) ----
    type_key = {
        FindingType.MAJOR_NC: "major",
        FindingType.MINOR_NC: "minor",
        FindingType.OBSERVATION: "observation",
        FindingType.OFI: "ofi",
    }
    heat = {c: {"major": 0, "minor": 0, "observation": 0, "ofi": 0} for c in _AS9100_CLAUSES}
    heat["Other"] = {"major": 0, "minor": 0, "observation": 0, "ofi": 0}
    for f in (
        db.execute(select(AuditFinding).where(AuditFinding.status != FindingStatus.CLOSED))
        .scalars()
        .all()
    ):
        ref = (f.clause_reference or "").strip()
        top = ref.split(".")[0] if ref else ""
        bucket = top if top in _AS9100_CLAUSES else "Other"
        heat[bucket][type_key.get(f.finding_type, "observation")] += 1

    clause_heatmap = []
    for c in [*_AS9100_CLAUSES, "Other"]:
        cell = heat[c]
        total = sum(cell.values())
        if c == "Other" and total == 0:
            continue
        clause_heatmap.append(
            {
                "clause": c,
                "title": _AS9100_CLAUSES.get(c, "Other / Unspecified"),
                **cell,
                "total": total,
            }
        )

    # ---- Compliance calendar (next 90 days + anything overdue) ----
    calendar: list[dict] = []

    def _add(kind: str, label: str, value) -> None:
        d = _as_date(value)
        if d is None or (d - today).days > 90:
            return
        days = (d - today).days
        status = "overdue" if days < 0 else "due_soon" if days <= 30 else "upcoming"
        calendar.append(
            {
                "type": kind,
                "label": label,
                "date": d.isoformat(),
                "days_remaining": days,
                "status": status,
            }
        )

    for s in (
        db.execute(
            select(Supplier).where(
                Supplier.is_deleted.is_(False), Supplier.cert_expiry.is_not(None)
            )
        )
        .scalars()
        .all()
    ):
        _add("Supplier cert", f"{s.name} — {s.certification or 'certification'}", s.cert_expiry)
    for e in (
        db.execute(
            select(Equipment).where(
                Equipment.status == EquipmentStatus.ACTIVE, Equipment.next_due_date.is_not(None)
            )
        )
        .scalars()
        .all()
    ):
        _add("Calibration", f"{e.asset_tag} — {e.name}", e.next_due_date)
    for a in (
        db.execute(
            select(Audit).where(
                Audit.is_deleted.is_(False),
                Audit.status.in_(_AUDIT_OPEN),
                Audit.planned_date.is_not(None),
            )
        )
        .scalars()
        .all()
    ):
        _add("Audit", f"{a.audit_number} — {a.title}", a.planned_date)
    for c in (
        db.execute(
            select(Capa).where(
                Capa.is_deleted.is_(False), Capa.status.in_(_CAPA_OPEN), Capa.due_date.is_not(None)
            )
        )
        .scalars()
        .all()
    ):
        _add("CAPA", f"{c.capa_number} — {c.title}", c.due_date)

    calendar.sort(key=lambda x: x["date"])
    compliance_calendar = calendar[:40]

    # ---- Executive KPIs vs target ----
    open_ncrs = _count(db, Nonconformance, Nonconformance.status.in_(_NCR_OPEN))
    overdue_capas = _count(
        db, Capa, Capa.status.in_(_CAPA_OPEN), Capa.due_date.is_not(None), Capa.due_date < today
    )
    open_findings = _count(db, AuditFinding, AuditFinding.status != FindingStatus.CLOSED)

    escapes = sum(
        1
        for c in db.execute(select(Complaint).where(Complaint.is_deleted.is_(False)))
        .scalars()
        .all()
        if (cd := _as_date(getattr(c, "created_at", None))) is not None and cd >= since
    )

    closed_n = on_time = 0
    for c in (
        db.execute(select(Capa).where(Capa.is_deleted.is_(False), Capa.status == CapaStatus.CLOSED))
        .scalars()
        .all()
    ):
        cl = _as_date(getattr(c, "closed_at", None))
        if cl is None or cl < since:
            continue
        closed_n += 1
        due = _as_date(getattr(c, "due_date", None))
        if due is None or cl <= due:
            on_time += 1
    on_time_rate = (on_time / closed_n * 100) if closed_n else 100.0

    avg_q = db.execute(select(func.avg(SupplierRating.quality_score))).scalar()
    avg_otd = db.execute(select(func.avg(SupplierRating.on_time_delivery))).scalar()

    kpis = [
        _exec_kpi(
            "open_ncrs", "Open Nonconformances", open_ncrs, target=_cfg("kpi_target_open_ncrs", 10)
        ),
        _exec_kpi(
            "overdue_capas",
            "Overdue CAPAs",
            overdue_capas,
            target=_cfg("kpi_target_overdue_capas", 0),
        ),
        _exec_kpi(
            "open_findings",
            "Open Audit Findings",
            open_findings,
            target=_cfg("kpi_target_open_findings", 5),
        ),
        _exec_kpi(
            "escapes_90d",
            "Customer Escapes (90d)",
            escapes,
            target=_cfg("kpi_target_escapes", 3),
        ),
        _exec_kpi(
            "capa_on_time",
            "On-time CAPA Closure",
            on_time_rate,
            unit="%",
            target=_cfg("kpi_target_capa_on_time", 90),
            direction="higher_better",
        ),
        _exec_kpi(
            "supplier_quality",
            "Supplier Quality",
            float(avg_q) if avg_q is not None else 0.0,
            unit="%",
            target=_cfg("kpi_target_supplier_quality", 95),
            direction="higher_better",
        ),
        _exec_kpi(
            "supplier_otd",
            "Supplier On-time Delivery",
            float(avg_otd) if avg_otd is not None else 0.0,
            unit="%",
            target=_cfg("kpi_target_supplier_otd", 95),
            direction="higher_better",
        ),
    ]

    return {
        "generated_at": today.isoformat(),
        "kpis": kpis,
        "coq_trend": coq_trend,
        "coq_current": coq_current,
        "clause_heatmap": clause_heatmap,
        "compliance_calendar": compliance_calendar,
    }


_CHANGE_OPEN = [
    ChangeStatus.DRAFT,
    ChangeStatus.SUBMITTED,
    ChangeStatus.UNDER_REVIEW,
    ChangeStatus.APPROVED,
]
_RISK_OPEN = [
    RiskStatus.IDENTIFIED,
    RiskStatus.ASSESSED,
    RiskStatus.TREATMENT_PLANNED,
    RiskStatus.MITIGATING,
    RiskStatus.MONITORING,
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

    for r in (
        db.execute(
            select(Nonconformance).where(
                Nonconformance.is_deleted.is_(False),
                Nonconformance.assigned_to == user_id,
                Nonconformance.status.in_(_NCR_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add("Nonconformance", r, "ncr_number", r.title, None, f"/nonconformances/{r.id}")

    for r in (
        db.execute(
            select(Capa).where(
                Capa.is_deleted.is_(False),
                Capa.owner_id == user_id,
                Capa.status.in_(_CAPA_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add("CAPA", r, "capa_number", r.title, getattr(r, "due_date", None), f"/capa/{r.id}")

    for r in (
        db.execute(
            select(Audit).where(
                Audit.is_deleted.is_(False),
                Audit.lead_auditor_id == user_id,
                Audit.status.in_(_AUDIT_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add(
            "Audit", r, "audit_number", r.title, getattr(r, "planned_date", None), f"/audits/{r.id}"
        )

    for r in (
        db.execute(
            select(ChangeOrder).where(
                ChangeOrder.is_deleted.is_(False),
                ChangeOrder.owner_id == user_id,
                ChangeOrder.status.in_(_CHANGE_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add(
            "Change",
            r,
            "change_number",
            r.title,
            getattr(r, "target_date", None),
            f"/changes/{r.id}",
        )

    for r in (
        db.execute(
            select(Risk).where(
                Risk.is_deleted.is_(False),
                Risk.owner_id == user_id,
                Risk.status.in_(_RISK_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add("Risk", r, "risk_number", r.title, getattr(r, "review_date", None), f"/risks/{r.id}")

    for r in (
        db.execute(
            select(Complaint).where(
                Complaint.is_deleted.is_(False),
                Complaint.assigned_to == user_id,
                Complaint.status.in_(_COMPLAINT_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add(
            "Complaint",
            r,
            "complaint_number",
            r.title,
            getattr(r, "due_date", None),
            f"/complaints/{r.id}",
        )

    for r in (
        db.execute(
            select(Inspection).where(
                Inspection.is_deleted.is_(False),
                Inspection.inspector_id == user_id,
                Inspection.result == InspectionResult.PENDING,
            )
        )
        .scalars()
        .all()
    ):
        add(
            "Inspection",
            r,
            "inspection_number",
            getattr(r, "part_number", None),
            None,
            f"/inspections/{r.id}",
        )

    for r in (
        db.execute(
            select(ManagementReview).where(
                ManagementReview.is_deleted.is_(False),
                ManagementReview.chairperson_id == user_id,
                ManagementReview.status.in_(_REVIEW_OPEN),
            )
        )
        .scalars()
        .all()
    ):
        add(
            "Management Review",
            r,
            "review_number",
            r.title,
            getattr(r, "due_date", None),
            f"/mgmt-reviews/{r.id}",
        )

    # Overdue first, then soonest due, then those without a due date.
    items.sort(key=lambda i: (not i["overdue"], i["due_date"] or "9999-99-99"))
    return items[:limit]


# Fixed category labels for auto-compiled clause 9.3 management-review inputs.
# Used to replace prior auto rows on re-run without touching manual inputs.
MGMT_REVIEW_AUTO_CATEGORIES = [
    "Status of Previous Actions",
    "Customer Satisfaction & Complaints",
    "Nonconformities & Corrective Actions",
    "Internal Audit Results",
    "External Provider (Supplier) Performance",
    "Monitoring & Measurement (Calibration)",
    "Risks & Opportunities",
]


def management_review_inputs(db: Session) -> list[dict]:
    """Compile ISO 9001 / AS9100 clause 9.3.2 management-review inputs from
    current QMS data. Returns ``{category, content, metric_value}`` rows.
    """
    ncr = open_ncr_metrics(db)
    capa = capa_metrics(db)
    findings = audit_finding_metrics(db)
    suppliers = supplier_metrics(db)
    complaints = complaint_metrics(db)
    cal = calibration_metrics(db)

    open_actions = _count(db, ActionItem, ActionItem.status != ActionItemStatus.COMPLETED)
    open_risks = _count(db, Risk, Risk.status.in_(_RISK_OPEN))

    by_type = ", ".join(f"{k}: {v}" for k, v in findings["open_by_type"].items()) or "none"
    avg_q = suppliers["avg_quality_score"]
    avg_otd = suppliers["avg_on_time_delivery"]
    q_txt = avg_q if avg_q is not None else "n/a"
    otd_txt = avg_otd if avg_otd is not None else "n/a"

    return [
        {
            "category": "Status of Previous Actions",
            "content": (
                f"{open_actions} management-review action item(s) remain open from prior reviews "
                "and require follow-up."
            ),
            "metric_value": f"{open_actions} open",
        },
        {
            "category": "Customer Satisfaction & Complaints",
            "content": (
                f"{complaints['open_total']} open customer complaint(s)/RMA(s). Review customer "
                "feedback and satisfaction trends."
            ),
            "metric_value": f"{complaints['open_total']} open",
        },
        {
            "category": "Nonconformities & Corrective Actions",
            "content": (
                f"{ncr['open_total']} open nonconformance(s) ({ncr['critical_open']} critical); "
                f"{capa['open_total']} open CAPA(s) with {capa['overdue']} overdue."
            ),
            "metric_value": f"{ncr['open_total']} NCR / {capa['open_total']} CAPA",
        },
        {
            "category": "Internal Audit Results",
            "content": f"{findings['open_findings']} open audit finding(s) by type — {by_type}.",
            "metric_value": f"{findings['open_findings']} open",
        },
        {
            "category": "External Provider (Supplier) Performance",
            "content": (
                f"{suppliers['approved_suppliers']} approved, "
                f"{suppliers['disqualified_suppliers']} disqualified supplier(s). "
                f"Avg quality {q_txt}, avg on-time delivery {otd_txt}."
            ),
            "metric_value": f"Q {q_txt} / OTD {otd_txt}",
        },
        {
            "category": "Monitoring & Measurement (Calibration)",
            "content": (
                f"{cal['active_equipment']} active gauge(s): {cal['overdue']} overdue, "
                f"{cal['due_soon']} due within {cal['due_within_days']} days."
            ),
            "metric_value": f"{cal['overdue']} overdue",
        },
        {
            "category": "Risks & Opportunities",
            "content": (
                f"{open_risks} risk(s) currently open and under management; review effectiveness "
                "of treatment actions and new opportunities."
            ),
            "metric_value": f"{open_risks} open",
        },
    ]
