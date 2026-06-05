"""Audit management: Audit, AuditFinding, AuditChecklistItem (AS9100)."""
from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class AuditType(str, enum.Enum):
    INTERNAL = "internal"
    EXTERNAL = "external"
    SUPPLIER = "supplier"
    CERTIFICATION = "certification"
    PROCESS = "process"


class AuditStatus(str, enum.Enum):
    PLANNED = "planned"
    IN_PROGRESS = "in_progress"
    REPORTING = "reporting"
    CLOSED = "closed"


class FindingType(str, enum.Enum):
    MAJOR_NC = "major_nonconformity"
    MINOR_NC = "minor_nonconformity"
    OBSERVATION = "observation"
    OFI = "opportunity_for_improvement"


class FindingStatus(str, enum.Enum):
    OPEN = "open"
    RESPONSE_SUBMITTED = "response_submitted"
    VERIFIED = "verified"
    CLOSED = "closed"


class Audit(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "audits"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    audit_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    audit_type: Mapped[AuditType] = mapped_column(Enum(AuditType, name="audit_type"), nullable=False)
    status: Mapped[AuditStatus] = mapped_column(
        Enum(AuditStatus, name="audit_status"), default=AuditStatus.PLANNED, nullable=False
    )
    standard: Mapped[str | None] = mapped_column(String(64), nullable=True)  # AS9100D, ISO9001
    scope: Mapped[str | None] = mapped_column(Text, nullable=True)
    lead_auditor_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    auditee_area: Mapped[str | None] = mapped_column(String(255), nullable=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    planned_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    actual_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    findings: Mapped[list[AuditFinding]] = relationship(
        "AuditFinding", back_populates="audit", cascade="all, delete-orphan"
    )
    checklist_items: Mapped[list[AuditChecklistItem]] = relationship(
        "AuditChecklistItem", back_populates="audit", cascade="all, delete-orphan"
    )


class AuditFinding(Base, TimestampMixin):
    __tablename__ = "audit_findings"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    audit_id: Mapped[int] = mapped_column(
        ForeignKey("audits.id", ondelete="CASCADE"), nullable=False, index=True
    )
    finding_number: Mapped[str] = mapped_column(String(32), nullable=False)
    finding_type: Mapped[FindingType] = mapped_column(
        Enum(FindingType, name="finding_type"), nullable=False
    )
    status: Mapped[FindingStatus] = mapped_column(
        Enum(FindingStatus, name="finding_status"), default=FindingStatus.OPEN, nullable=False
    )
    clause_reference: Mapped[str | None] = mapped_column(String(64), nullable=True)
    description: Mapped[str] = mapped_column(Text, nullable=False)
    evidence: Mapped[str | None] = mapped_column(Text, nullable=True)
    response_due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    capa_id: Mapped[int | None] = mapped_column(ForeignKey("capas.id"), nullable=True)

    audit: Mapped[Audit] = relationship("Audit", back_populates="findings")


class AuditChecklistItem(Base):
    __tablename__ = "audit_checklist_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    audit_id: Mapped[int] = mapped_column(
        ForeignKey("audits.id", ondelete="CASCADE"), nullable=False, index=True
    )
    clause_reference: Mapped[str | None] = mapped_column(String(64), nullable=True)
    question: Mapped[str] = mapped_column(Text, nullable=False)
    result: Mapped[str | None] = mapped_column(String(16), nullable=True)  # conform/nonconform/na
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)

    audit: Mapped[Audit] = relationship("Audit", back_populates="checklist_items")
