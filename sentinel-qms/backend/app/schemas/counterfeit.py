"""Counterfeit-parts prevention schemas (AS5553/AS6081)."""

from __future__ import annotations

from datetime import date

from pydantic import BaseModel, Field

from app.models.counterfeit import (
    AlertSource,
    AlertStatus,
    RiskLevel,
    SourceType,
    VerificationStatus,
)
from app.schemas.common import ORMModel


# ---- Part sourcing records ----
class SourcingCreate(BaseModel):
    part_number: str = Field(..., min_length=1, max_length=128)
    description: str | None = Field(default=None, max_length=512)
    supplier_id: int | None = None
    source_type: SourceType = SourceType.OCM
    lot_date_code: str | None = Field(default=None, max_length=128)
    quantity: int | None = Field(default=None, ge=0)
    coc_received: bool = False
    traceability_to_oem: bool = False
    inspection_method: str | None = Field(default=None, max_length=255)
    risk_level: RiskLevel = RiskLevel.MEDIUM
    notes: str | None = None


class SourcingUpdate(BaseModel):
    part_number: str | None = Field(default=None, min_length=1, max_length=128)
    description: str | None = Field(default=None, max_length=512)
    supplier_id: int | None = None
    source_type: SourceType | None = None
    lot_date_code: str | None = Field(default=None, max_length=128)
    quantity: int | None = Field(default=None, ge=0)
    coc_received: bool | None = None
    traceability_to_oem: bool | None = None
    inspection_method: str | None = Field(default=None, max_length=255)
    risk_level: RiskLevel | None = None
    status: VerificationStatus | None = None
    notes: str | None = None


class SourcingRead(ORMModel):
    id: int
    record_number: str
    part_number: str
    description: str | None
    supplier_id: int | None
    source_type: SourceType
    lot_date_code: str | None
    quantity: int | None
    coc_received: bool
    traceability_to_oem: bool
    inspection_method: str | None
    risk_level: RiskLevel
    status: VerificationStatus
    notes: str | None
    ncr_id: int | None


# ---- Counterfeit alerts (GIDEP/ERAI) ----
class AlertCreate(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    source: AlertSource = AlertSource.GIDEP
    external_ref: str | None = Field(default=None, max_length=128)
    part_numbers: str | None = None
    description: str | None = None
    alert_date: date | None = None
    impact_assessment: str | None = None
    affects_inventory: bool = False


class AlertUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=512)
    source: AlertSource | None = None
    external_ref: str | None = Field(default=None, max_length=128)
    part_numbers: str | None = None
    description: str | None = None
    alert_date: date | None = None
    status: AlertStatus | None = None
    impact_assessment: str | None = None
    affects_inventory: bool | None = None


class AlertRead(ORMModel):
    id: int
    alert_number: str
    source: AlertSource
    external_ref: str | None
    title: str
    part_numbers: str | None
    description: str | None
    alert_date: date | None
    status: AlertStatus
    impact_assessment: str | None
    affects_inventory: bool
    ncr_id: int | None


class NcrLinkResult(BaseModel):
    ncr_id: int
    ncr_number: str
