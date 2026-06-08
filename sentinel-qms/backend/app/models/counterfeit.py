"""Counterfeit-parts prevention (AS5553 / AS6081).

Two record types:

* :class:`PartSourcingRecord` — provenance & verification of a procured part
  (source type, certificate of conformance, OEM traceability, risk, status).
* :class:`CounterfeitAlert` — a GIDEP/ERAI-style alert log with impact
  assessment and inventory-impact flag.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Boolean, Date, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class SourceType(str, enum.Enum):
    OCM = "ocm"  # original component manufacturer
    FRANCHISED = "franchised"  # authorized / franchised distributor
    INDEPENDENT = "independent"  # independent distributor
    BROKER = "broker"
    OTHER = "other"


class RiskLevel(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"
    CRITICAL = "critical"


class VerificationStatus(str, enum.Enum):
    PENDING = "pending"
    VERIFIED = "verified"
    SUSPECT = "suspect"
    REJECTED = "rejected"


class AlertSource(str, enum.Enum):
    GIDEP = "gidep"
    ERAI = "erai"
    INTERNAL = "internal"
    CUSTOMER = "customer"
    SUPPLIER = "supplier"
    OTHER = "other"


class AlertStatus(str, enum.Enum):
    OPEN = "open"
    UNDER_ASSESSMENT = "under_assessment"
    CLOSED = "closed"


class PartSourcingRecord(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "part_sourcing_records"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    record_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    part_number: Mapped[str] = mapped_column(String(128), nullable=False, index=True)
    description: Mapped[str | None] = mapped_column(String(512), nullable=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    source_type: Mapped[SourceType] = mapped_column(
        Enum(SourceType, name="cfp_source_type"), default=SourceType.OCM, nullable=False
    )
    lot_date_code: Mapped[str | None] = mapped_column(String(128), nullable=True)
    quantity: Mapped[int | None] = mapped_column(Integer, nullable=True)
    coc_received: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    traceability_to_oem: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    inspection_method: Mapped[str | None] = mapped_column(String(255), nullable=True)
    risk_level: Mapped[RiskLevel] = mapped_column(
        Enum(RiskLevel, name="cfp_risk_level"), default=RiskLevel.MEDIUM, nullable=False
    )
    status: Mapped[VerificationStatus] = mapped_column(
        Enum(VerificationStatus, name="cfp_verification_status"),
        default=VerificationStatus.PENDING,
        nullable=False,
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Set when an NCR has been raised from this suspect part.
    ncr_id: Mapped[int | None] = mapped_column(ForeignKey("nonconformances.id"), nullable=True)


class CounterfeitAlert(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "counterfeit_alerts"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    alert_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    source: Mapped[AlertSource] = mapped_column(
        Enum(AlertSource, name="cfa_source"), default=AlertSource.GIDEP, nullable=False
    )
    # External reference, e.g. a GIDEP document or ERAI alert number.
    external_ref: Mapped[str | None] = mapped_column(String(128), nullable=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    part_numbers: Mapped[str | None] = mapped_column(Text, nullable=True)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    alert_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    status: Mapped[AlertStatus] = mapped_column(
        Enum(AlertStatus, name="cfa_status"), default=AlertStatus.OPEN, nullable=False
    )
    impact_assessment: Mapped[str | None] = mapped_column(Text, nullable=True)
    affects_inventory: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    # Set when an NCR has been raised from this alert.
    ncr_id: Mapped[int | None] = mapped_column(ForeignKey("nonconformances.id"), nullable=True)
