"""Corrective & Preventive Action (CAPA) with 8D problem-solving structure."""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import (
    Date,
    DateTime,
    Enum,
    ForeignKey,
    Integer,
    String,
    Text,
)
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class CapaStatus(str, enum.Enum):
    OPEN = "open"
    CONTAINMENT = "containment"
    ROOT_CAUSE = "root_cause"
    ACTION_PLAN = "action_plan"
    IMPLEMENTATION = "implementation"
    VERIFICATION = "verification"
    CLOSED = "closed"
    CANCELLED = "cancelled"


class CapaType(str, enum.Enum):
    CORRECTIVE = "corrective"
    PREVENTIVE = "preventive"


class CapaActionStatus(str, enum.Enum):
    OPEN = "open"
    IN_PROGRESS = "in_progress"
    COMPLETED = "completed"
    VERIFIED = "verified"


class Capa(Base, TimestampMixin, SoftDeleteMixin):
    """8D-structured CAPA record (D1–D8)."""

    __tablename__ = "capas"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    capa_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    capa_type: Mapped[CapaType] = mapped_column(
        Enum(CapaType, name="capa_type"), default=CapaType.CORRECTIVE, nullable=False
    )
    status: Mapped[CapaStatus] = mapped_column(
        Enum(CapaStatus, name="capa_status"), default=CapaStatus.OPEN, nullable=False, index=True
    )

    # D1 — Team
    d1_team: Mapped[str | None] = mapped_column(Text, nullable=True)
    # D2 — Problem description
    d2_problem_description: Mapped[str] = mapped_column(Text, nullable=False)
    # D3 — Interim containment action
    d3_containment: Mapped[str | None] = mapped_column(Text, nullable=True)
    # D4 — Root cause
    d4_root_cause: Mapped[str | None] = mapped_column(Text, nullable=True)
    root_cause_method: Mapped[str | None] = mapped_column(
        String(64), nullable=True
    )  # 5why/fishbone
    # D5 — Permanent corrective action (chosen)
    d5_corrective_action: Mapped[str | None] = mapped_column(Text, nullable=True)
    # D6 — Implement & validate
    d6_implementation: Mapped[str | None] = mapped_column(Text, nullable=True)
    # D7 — Prevent recurrence
    d7_preventive_action: Mapped[str | None] = mapped_column(Text, nullable=True)
    # D8 — Closure / recognition
    d8_closure: Mapped[str | None] = mapped_column(Text, nullable=True)

    effectiveness_verified: Mapped[bool] = mapped_column(default=False, nullable=False)
    effectiveness_notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    effectiveness_verified_by: Mapped[int | None] = mapped_column(
        ForeignKey("users.id"), nullable=True
    )
    effectiveness_verified_at: Mapped[datetime | None] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    supplier_id: Mapped[int | None] = mapped_column(ForeignKey("suppliers.id"), nullable=True)
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    closed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    closure_signature_id: Mapped[int | None] = mapped_column(
        ForeignKey("electronic_signatures.id"), nullable=True
    )

    actions: Mapped[list[CapaAction]] = relationship(
        "CapaAction",
        back_populates="capa",
        cascade="all, delete-orphan",
        order_by="CapaAction.id",
    )


class CapaAction(Base, TimestampMixin):
    __tablename__ = "capa_actions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    capa_id: Mapped[int] = mapped_column(
        ForeignKey("capas.id", ondelete="CASCADE"), nullable=False, index=True
    )
    description: Mapped[str] = mapped_column(Text, nullable=False)
    action_kind: Mapped[str] = mapped_column(String(32), default="corrective", nullable=False)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    status: Mapped[CapaActionStatus] = mapped_column(
        Enum(CapaActionStatus, name="capa_action_status"),
        default=CapaActionStatus.OPEN,
        nullable=False,
    )
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    capa: Mapped[Capa] = relationship("Capa", back_populates="actions")
