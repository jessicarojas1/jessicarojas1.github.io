"""Reports & Exports API: JSON aggregates powering the Reports workspace.

All aggregation is performed in Python over fetched rows so the endpoints stay
portable across PostgreSQL and SQLite (no DB-specific date functions).
"""
from __future__ import annotations

from datetime import date, datetime, timezone

from fastapi import APIRouter, Depends, Query
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.api.deps import get_current_user
from app.core.database import get_db
from app.models.audit_mgmt import Audit, AuditFinding
from app.models.capa import Capa, CapaStatus
from app.models.nonconformance import NcStatus, Nonconformance
from app.models.supplier import ScarStatus, Supplier, SupplierRating, SupplierScar
from app.schemas.auth import CurrentUser
from app.schemas.report import (
    AgingBucket,
    AuditSummaryReport,
    CapaSummaryReport,
    LabelCount,
    MonthTrend,
    NcrSummaryReport,
    SupplierScorecardReport,
    SupplierScorecardRow,
)

router = APIRouter(prefix="/reports", tags=["reports"])

_NCR_OPEN = {NcStatus.OPEN, NcStatus.UNDER_REVIEW, NcStatus.DISPOSITIONED}
_CAPA_OPEN = {
    CapaStatus.OPEN,
    CapaStatus.CONTAINMENT,
    CapaStatus.ROOT_CAUSE,
    CapaStatus.ACTION_PLAN,
    CapaStatus.IMPLEMENTATION,
    CapaStatus.VERIFICATION,
}


def _today() -> date:
    return datetime.now(timezone.utc).date()


def _enum_label(value) -> str:
    if value is None:
        return "—"
    return value.value if hasattr(value, "value") else str(value)


def _month_key(value) -> str | None:
    if value is None:
        return None
    return f"{value.year:04d}-{value.month:02d}"


def _month_window(months: int) -> list[str]:
    """Last ``months`` month keys, oldest first, including the current month."""
    today = _today()
    year, month = today.year, today.month
    keys: list[str] = []
    for _ in range(months):
        keys.append(f"{year:04d}-{month:02d}")
        month -= 1
        if month == 0:
            month = 12
            year -= 1
    return list(reversed(keys))


def _as_date(value):
    if value is None:
        return None
    return value.date() if hasattr(value, "date") else value


@router.get("/ncr-summary", response_model=NcrSummaryReport)
def ncr_summary(
    months: int = Query(12, ge=1, le=36),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> NcrSummaryReport:
    rows = (
        db.execute(
            select(Nonconformance).where(Nonconformance.is_deleted.is_(False))
        )
        .scalars()
        .all()
    )

    window = _month_window(months)
    window_set = set(window)
    by_status: dict[str, int] = {}
    by_severity: dict[str, int] = {}
    opened = {k: 0 for k in window}
    closed = {k: 0 for k in window}
    total_open = 0

    for r in rows:
        status_label = _enum_label(r.status)
        by_status[status_label] = by_status.get(status_label, 0) + 1
        sev_label = _enum_label(r.severity)
        by_severity[sev_label] = by_severity.get(sev_label, 0) + 1
        if r.status in _NCR_OPEN:
            total_open += 1
        ok = _month_key(getattr(r, "created_at", None))
        if ok in window_set:
            opened[ok] += 1
        if r.status == NcStatus.CLOSED:
            ck = _month_key(getattr(r, "closed_at", None) or getattr(r, "updated_at", None))
            if ck in window_set:
                closed[ck] += 1

    return NcrSummaryReport(
        by_status=[LabelCount(label=k, count=v) for k, v in by_status.items()],
        by_severity=[LabelCount(label=k, count=v) for k, v in by_severity.items()],
        by_month=[MonthTrend(month=k, opened=opened[k], closed=closed[k]) for k in window],
        total_open=total_open,
        total=len(rows),
    )


@router.get("/capa-summary", response_model=CapaSummaryReport)
def capa_summary(
    months: int = Query(12, ge=1, le=36),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> CapaSummaryReport:
    # ``months`` is accepted for API symmetry; aging is age-based, not windowed.
    rows = db.execute(select(Capa).where(Capa.is_deleted.is_(False))).scalars().all()
    today = _today()

    by_status: dict[str, int] = {}
    aging = {"0-30": 0, "31-60": 0, "61-90": 0, "90+": 0}
    overdue = 0
    total_open = 0
    open_age_days: list[int] = []

    for r in rows:
        by_status[_enum_label(r.status)] = by_status.get(_enum_label(r.status), 0) + 1
        if r.status in _CAPA_OPEN:
            total_open += 1
            due = _as_date(getattr(r, "due_date", None))
            if due is not None and due < today:
                overdue += 1
            created = _as_date(getattr(r, "created_at", None))
            if created is not None:
                days = (today - created).days
                open_age_days.append(days)
                if days <= 30:
                    aging["0-30"] += 1
                elif days <= 60:
                    aging["31-60"] += 1
                elif days <= 90:
                    aging["61-90"] += 1
                else:
                    aging["90+"] += 1

    avg_days_open = round(sum(open_age_days) / len(open_age_days), 1) if open_age_days else 0.0

    return CapaSummaryReport(
        by_status=[LabelCount(label=k, count=v) for k, v in by_status.items()],
        aging=[AgingBucket(bucket=b, count=c) for b, c in aging.items()],
        overdue=overdue,
        total_open=total_open,
        avg_days_open=avg_days_open,
    )


@router.get("/supplier-scorecard", response_model=SupplierScorecardReport)
def supplier_scorecard(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> SupplierScorecardReport:
    suppliers = (
        db.execute(select(Supplier).where(Supplier.is_deleted.is_(False)))
        .scalars()
        .all()
    )

    # Aggregate ratings (avg quality + avg OTD, rating count) per supplier.
    rating_q: dict[int, list[float]] = {}
    rating_otd: dict[int, list[float]] = {}
    rating_n: dict[int, int] = {}
    for rt in db.execute(select(SupplierRating)).scalars().all():
        rating_n[rt.supplier_id] = rating_n.get(rt.supplier_id, 0) + 1
        if rt.quality_score is not None:
            rating_q.setdefault(rt.supplier_id, []).append(float(rt.quality_score))
        if rt.on_time_delivery is not None:
            rating_otd.setdefault(rt.supplier_id, []).append(float(rt.on_time_delivery))

    # Open SCARs (any status other than CLOSED) per supplier.
    open_scars: dict[int, int] = {}
    for sc in db.execute(select(SupplierScar)).scalars().all():
        if sc.status != ScarStatus.CLOSED:
            open_scars[sc.supplier_id] = open_scars.get(sc.supplier_id, 0) + 1

    def _avg(values: list[float]) -> float | None:
        return round(sum(values) / len(values), 2) if values else None

    rows = [
        SupplierScorecardRow(
            name=s.name,
            status=_enum_label(s.status),
            quality_score=_avg(rating_q.get(s.id, [])),
            on_time_delivery=_avg(rating_otd.get(s.id, [])),
            open_scars=open_scars.get(s.id, 0),
            rating_count=rating_n.get(s.id, 0),
        )
        for s in suppliers
    ]
    rows.sort(key=lambda r: r.name.lower())

    return SupplierScorecardReport(suppliers=rows)


@router.get("/audit-summary", response_model=AuditSummaryReport)
def audit_summary(
    months: int = Query(12, ge=1, le=36),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(get_current_user),
) -> AuditSummaryReport:
    audits = (
        db.execute(select(Audit).where(Audit.is_deleted.is_(False))).scalars().all()
    )

    by_type: dict[str, int] = {}
    by_status: dict[str, int] = {}
    for a in audits:
        by_type[_enum_label(a.audit_type)] = by_type.get(_enum_label(a.audit_type), 0) + 1
        by_status[_enum_label(a.status)] = by_status.get(_enum_label(a.status), 0) + 1

    findings_by_type: dict[str, int] = {}
    for f in db.execute(select(AuditFinding)).scalars().all():
        label = _enum_label(f.finding_type)
        findings_by_type[label] = findings_by_type.get(label, 0) + 1

    return AuditSummaryReport(
        by_type=[LabelCount(label=k, count=v) for k, v in by_type.items()],
        by_status=[LabelCount(label=k, count=v) for k, v in by_status.items()],
        findings_by_type=[LabelCount(label=k, count=v) for k, v in findings_by_type.items()],
        total=len(audits),
    )
