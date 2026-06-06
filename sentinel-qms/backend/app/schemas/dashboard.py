"""Dashboard KPI response schemas."""
from __future__ import annotations

from pydantic import BaseModel


class NcrKpi(BaseModel):
    open_total: int
    critical_open: int
    by_severity: dict[str, int]


class CapaKpi(BaseModel):
    open_total: int
    overdue: int
    awaiting_effectiveness_verification: int


class CalibrationKpi(BaseModel):
    active_equipment: int
    overdue: int
    due_within_days: int
    due_soon: int


class AuditFindingKpi(BaseModel):
    open_findings: int
    open_by_type: dict[str, int]


class SupplierKpi(BaseModel):
    approved_suppliers: int
    disqualified_suppliers: int
    avg_quality_score: float | None
    avg_on_time_delivery: float | None


class ComplaintKpi(BaseModel):
    open_total: int


class DashboardKpis(BaseModel):
    open_ncrs: int
    open_capas: int
    overdue_capas: int
    calibration_due: int
    calibration_overdue: int
    open_audits: int
    supplier_avg_rating: float
    open_complaints: int


class DashTrendPoint(BaseModel):
    month: str
    opened: int
    closed: int


class AgingBucket(BaseModel):
    bucket: str
    count: int


class NameValue(BaseModel):
    name: str
    value: int


class SupplierPerformance(BaseModel):
    name: str
    rating: float
    otd: float


class ClauseCount(BaseModel):
    clause: str
    count: int


class DashboardSummary(BaseModel):
    kpis: DashboardKpis
    ncr_trend: list[DashTrendPoint]
    capa_aging: list[AgingBucket]
    calibration_status: list[NameValue]
    supplier_performance: list[SupplierPerformance]
    findings_by_clause: list[ClauseCount]


# Legacy detailed aggregate (still available via the per-domain KPI endpoints).
class DashboardDetail(BaseModel):
    nonconformances: NcrKpi
    capa: CapaKpi
    calibration: CalibrationKpi
    audit_findings: AuditFindingKpi
    suppliers: SupplierKpi
    complaints: ComplaintKpi
    generated_at: str
