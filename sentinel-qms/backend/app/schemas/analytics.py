"""Analytics / trends response schemas."""

from __future__ import annotations

from pydantic import BaseModel


class TrendPoint(BaseModel):
    month: str  # "YYYY-MM"
    opened: int
    closed: int


class AnalyticsTrends(BaseModel):
    ncr_trend: list[TrendPoint]
    capa_trend: list[TrendPoint]
    open_by_module: dict[str, int]
    nc_by_severity: dict[str, int]
    audit_findings_by_type: dict[str, int]
