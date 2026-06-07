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


class ExecKpi(BaseModel):
    key: str
    label: str
    value: float
    unit: str
    target: float | None
    direction: str
    status: str


class CoqMonth(BaseModel):
    month: str
    prevention: int
    appraisal: int
    internal_failure: int
    external_failure: int
    prevention_cost: float
    appraisal_cost: float
    internal_failure_cost: float
    external_failure_cost: float


class CoqCurrent(BaseModel):
    prevention: int
    appraisal: int
    internal_failure: int
    external_failure: int
    total: int
    prevention_cost: float
    appraisal_cost: float
    internal_failure_cost: float
    external_failure_cost: float
    total_cost: float


class ClauseHeat(BaseModel):
    clause: str
    title: str
    major: int
    minor: int
    observation: int
    ofi: int
    total: int


class CalendarItem(BaseModel):
    type: str
    label: str
    date: str
    days_remaining: int
    status: str


class ExecutiveDashboard(BaseModel):
    generated_at: str
    kpis: list[ExecKpi]
    coq_trend: list[CoqMonth]
    coq_current: CoqCurrent
    clause_heatmap: list[ClauseHeat]
    compliance_calendar: list[CalendarItem]


class MyOpenItem(BaseModel):
    type: str
    id: int
    number: str
    title: str
    status: str
    due_date: str | None = None
    overdue: bool
    url: str


# Legacy detailed aggregate (still available via the per-domain KPI endpoints).
class DashboardDetail(BaseModel):
    nonconformances: NcrKpi
    capa: CapaKpi
    calibration: CalibrationKpi
    audit_findings: AuditFindingKpi
    suppliers: SupplierKpi
    complaints: ComplaintKpi
    generated_at: str
