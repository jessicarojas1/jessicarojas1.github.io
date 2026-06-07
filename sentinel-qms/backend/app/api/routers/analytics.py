"""Analytics / trends API: monthly time series and current open counts."""

from __future__ import annotations

from datetime import UTC, date, datetime

from fastapi import APIRouter, Depends, Query
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.api.deps import require_page
from app.core.database import get_db
from app.models.audit_mgmt import Audit, AuditFinding, AuditStatus
from app.models.calibration import Equipment  # noqa: F401 (kept for parity)
from app.models.capa import Capa, CapaStatus
from app.models.change import ChangeOrder, ChangeStatus
from app.models.complaint import Complaint, ComplaintStatus
from app.models.inspection import Inspection, InspectionResult
from app.models.nonconformance import NcSeverity, NcStatus, Nonconformance
from app.models.risk import Risk, RiskStatus
from app.schemas.analytics import AnalyticsTrends, TrendPoint
from app.schemas.auth import CurrentUser

router = APIRouter(prefix="/analytics", tags=["analytics"])

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
_CHANGE_OPEN = [
    ChangeStatus.DRAFT,
    ChangeStatus.SUBMITTED,
    ChangeStatus.UNDER_REVIEW,
    ChangeStatus.APPROVED,
    ChangeStatus.IMPLEMENTED,
]
_RISK_OPEN = [
    RiskStatus.IDENTIFIED,
    RiskStatus.ASSESSED,
    RiskStatus.TREATMENT_PLANNED,
    RiskStatus.MITIGATING,
    RiskStatus.MONITORING,
]


def _month_key(value: datetime | date | None) -> str | None:
    if value is None:
        return None
    return f"{value.year:04d}-{value.month:02d}"


def _month_window(months: int) -> list[str]:
    """Return the last ``months`` month keys, oldest first, including current."""
    today = datetime.now(UTC).date()
    year, month = today.year, today.month
    keys: list[str] = []
    for _ in range(months):
        keys.append(f"{year:04d}-{month:02d}")
        month -= 1
        if month == 0:
            month = 12
            year -= 1
    return list(reversed(keys))


def _trend(
    db: Session,
    model,
    *,
    closed_states: list,
    months: list[str],
) -> list[TrendPoint]:
    """Build {opened, closed} per month for a model with created_at/status."""
    has_closed_at = hasattr(model, "closed_at")
    opened: dict[str, int] = dict.fromkeys(months, 0)
    closed: dict[str, int] = dict.fromkeys(months, 0)
    window = set(months)

    stmt = select(model)
    if hasattr(model, "is_deleted"):
        stmt = stmt.where(model.is_deleted.is_(False))
    for row in db.execute(stmt).scalars().all():
        ok = _month_key(getattr(row, "created_at", None))
        if ok in window:
            opened[ok] += 1
        if row.status in closed_states:
            closed_dt = getattr(row, "closed_at", None) if has_closed_at else None
            if closed_dt is None:
                closed_dt = getattr(row, "updated_at", None)
            ck = _month_key(closed_dt)
            if ck in window:
                closed[ck] += 1
    return [TrendPoint(month=k, opened=opened[k], closed=closed[k]) for k in months]


def _count_open(db: Session, model, states: list) -> int:
    stmt = select(func.count()).select_from(model).where(model.status.in_(states))
    if hasattr(model, "is_deleted"):
        stmt = stmt.where(model.is_deleted.is_(False))
    return int(db.execute(stmt).scalar_one())


@router.get("/trends", response_model=AnalyticsTrends)
def trends(
    months: int = Query(6, ge=1, le=36),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_page("analytics", "view")),
) -> AnalyticsTrends:
    window = _month_window(months)

    ncr_trend = _trend(db, Nonconformance, closed_states=[NcStatus.CLOSED], months=window)
    capa_trend = _trend(db, Capa, closed_states=[CapaStatus.CLOSED], months=window)

    open_by_module = {
        "ncr": _count_open(db, Nonconformance, _NCR_OPEN),
        "capa": _count_open(db, Capa, _CAPA_OPEN),
        "audit": _count_open(db, Audit, _AUDIT_OPEN),
        "complaint": _count_open(db, Complaint, _COMPLAINT_OPEN),
        "risk": _count_open(db, Risk, _RISK_OPEN),
        "change": _count_open(db, ChangeOrder, _CHANGE_OPEN),
        # Inspections track state via `result`, not `status`.
        "inspection": int(
            db.execute(
                select(func.count())
                .select_from(Inspection)
                .where(
                    Inspection.result == InspectionResult.PENDING,
                    Inspection.is_deleted.is_(False),
                )
            ).scalar_one()
        ),
    }

    nc_by_severity = {sev.value: 0 for sev in NcSeverity}
    sev_rows = db.execute(
        select(Nonconformance.severity, func.count())
        .where(Nonconformance.is_deleted.is_(False))
        .group_by(Nonconformance.severity)
    ).all()
    for sev, count in sev_rows:
        nc_by_severity[sev.value if hasattr(sev, "value") else str(sev)] = int(count)

    findings_by_type: dict[str, int] = {}
    ftype_rows = db.execute(
        select(AuditFinding.finding_type, func.count()).group_by(AuditFinding.finding_type)
    ).all()
    for ftype, count in ftype_rows:
        findings_by_type[ftype.value if hasattr(ftype, "value") else str(ftype)] = int(count)

    return AnalyticsTrends(
        ncr_trend=ncr_trend,
        capa_trend=capa_trend,
        open_by_module=open_by_module,
        nc_by_severity=nc_by_severity,
        audit_findings_by_type=findings_by_type,
    )
