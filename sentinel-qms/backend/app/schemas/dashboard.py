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


class DashboardSummary(BaseModel):
    nonconformances: NcrKpi
    capa: CapaKpi
    calibration: CalibrationKpi
    audit_findings: AuditFindingKpi
    suppliers: SupplierKpi
    complaints: ComplaintKpi
    generated_at: str
