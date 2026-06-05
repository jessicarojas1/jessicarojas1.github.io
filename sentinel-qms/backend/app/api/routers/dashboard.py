"""Dashboard KPI endpoints."""
from __future__ import annotations

from fastapi import APIRouter, Depends, Query
from sqlalchemy.orm import Session

from app.core.database import get_db
from app.core.rbac import Permission, require_permission
from app.schemas.auth import CurrentUser
from app.schemas.dashboard import (
    AuditFindingKpi,
    CalibrationKpi,
    CapaKpi,
    ComplaintKpi,
    DashboardSummary,
    NcrKpi,
    SupplierKpi,
)
from app.services import kpi

router = APIRouter(prefix="/dashboard", tags=["dashboard"])


@router.get("/summary", response_model=DashboardSummary)
def summary(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> DashboardSummary:
    return DashboardSummary(**kpi.dashboard_summary(db))


@router.get("/nonconformances", response_model=NcrKpi)
def ncr_kpis(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> NcrKpi:
    return NcrKpi(**kpi.open_ncr_metrics(db))


@router.get("/capa", response_model=CapaKpi)
def capa_kpis(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> CapaKpi:
    return CapaKpi(**kpi.capa_metrics(db))


@router.get("/calibration", response_model=CalibrationKpi)
def calibration_kpis(
    soon_days: int = Query(30, ge=1, le=365),
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> CalibrationKpi:
    return CalibrationKpi(**kpi.calibration_metrics(db, soon_days=soon_days))


@router.get("/audit-findings", response_model=AuditFindingKpi)
def audit_finding_kpis(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> AuditFindingKpi:
    return AuditFindingKpi(**kpi.audit_finding_metrics(db))


@router.get("/suppliers", response_model=SupplierKpi)
def supplier_kpis(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> SupplierKpi:
    return SupplierKpi(**kpi.supplier_metrics(db))


@router.get("/complaints", response_model=ComplaintKpi)
def complaint_kpis(
    db: Session = Depends(get_db),
    _: CurrentUser = Depends(require_permission(Permission.DASHBOARD_READ)),
) -> ComplaintKpi:
    return ComplaintKpi(**kpi.complaint_metrics(db))
