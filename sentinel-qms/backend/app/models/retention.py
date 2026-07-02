"""Records Retention & Disposition Schedule (documented retention policy).

A :class:`RetentionPolicy` is a documented, per-record-category retention rule:
how long a class of controlled records must be kept, what event starts the
retention clock, and what action is *scheduled* (not automated) at the end of
the retention period. A ``legal_hold`` flag suspends any disposition regardless
of the retention period.

Honest scope: this module records the retention *schedule* and a legal-hold
flag. It does NOT automatically destroy, archive, or otherwise mutate records —
the disposition action is a documented, manually-performed step. Nothing here
executes a disposition on its own.
"""

from __future__ import annotations

import enum

from sqlalchemy import Boolean, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class RetentionCategory(str, enum.Enum):
    QUALITY_RECORDS = "quality_records"
    DESIGN_RECORDS = "design_records"
    SUPPLIER_RECORDS = "supplier_records"
    CALIBRATION_RECORDS = "calibration_records"
    TRAINING_RECORDS = "training_records"
    AUDIT_RECORDS = "audit_records"
    CAPA_RECORDS = "capa_records"
    CONTRACT_RECORDS = "contract_records"
    INSPECTION_RECORDS = "inspection_records"
    OTHER = "other"


class RetentionTrigger(str, enum.Enum):
    """The event that starts the retention clock."""

    CREATION = "creation"
    CLOSURE = "closure"
    DELIVERY = "delivery"
    CONTRACT_END = "contract_end"
    OBSOLESCENCE = "obsolescence"
    SUPERSEDED = "superseded"


class DispositionAction(str, enum.Enum):
    """The action SCHEDULED at end of retention — performed manually, not automated."""

    REVIEW = "review"
    ARCHIVE = "archive"
    DESTROY = "destroy"
    PERMANENT = "permanent"


class RetentionStatus(str, enum.Enum):
    DRAFT = "draft"
    ACTIVE = "active"
    SUPERSEDED = "superseded"


class RetentionPolicy(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "retention_policies"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    policy_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    record_category: Mapped[RetentionCategory] = mapped_column(
        Enum(RetentionCategory, name="retention_category"),
        default=RetentionCategory.OTHER,
        nullable=False,
    )
    retention_trigger: Mapped[RetentionTrigger] = mapped_column(
        Enum(RetentionTrigger, name="retention_trigger"),
        default=RetentionTrigger.CREATION,
        nullable=False,
    )
    # Nullable => "permanent/indefinite" (used with disposition_action=permanent).
    retention_years: Mapped[int | None] = mapped_column(Integer, nullable=True)
    disposition_action: Mapped[DispositionAction] = mapped_column(
        Enum(DispositionAction, name="disposition_action"),
        default=DispositionAction.REVIEW,
        nullable=False,
    )
    # When true, disposition is suspended regardless of retention_years.
    legal_hold: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    # e.g. "AS9100D 7.5.3.1", "DFARS 252.204-7012", "ITAR 22 CFR 122.5".
    authority_reference: Mapped[str | None] = mapped_column(String(255), nullable=True)
    status: Mapped[RetentionStatus] = mapped_column(
        Enum(RetentionStatus, name="retention_status"),
        default=RetentionStatus.DRAFT,
        nullable=False,
    )
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
