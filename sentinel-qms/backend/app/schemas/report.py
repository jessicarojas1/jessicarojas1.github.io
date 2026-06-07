"""Reports & Exports response schemas."""

from __future__ import annotations

from pydantic import BaseModel


class LabelCount(BaseModel):
    label: str
    count: int


class MonthTrend(BaseModel):
    month: str  # "YYYY-MM"
    opened: int
    closed: int


class AgingBucket(BaseModel):
    bucket: str
    count: int


class NcrSummaryReport(BaseModel):
    by_status: list[LabelCount]
    by_severity: list[LabelCount]
    by_month: list[MonthTrend]
    total_open: int
    total: int


class CapaSummaryReport(BaseModel):
    by_status: list[LabelCount]
    aging: list[AgingBucket]
    overdue: int
    total_open: int
    avg_days_open: float


class SupplierScorecardRow(BaseModel):
    name: str
    status: str
    quality_score: float | None
    on_time_delivery: float | None
    open_scars: int
    rating_count: int


class SupplierScorecardReport(BaseModel):
    suppliers: list[SupplierScorecardRow]


class AuditSummaryReport(BaseModel):
    by_type: list[LabelCount]
    by_status: list[LabelCount]
    findings_by_type: list[LabelCount]
    total: int
