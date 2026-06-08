"""Internal audit program — the annual / multi-year audit schedule.

An :class:`AuditProgram` is the plan for a period (typically a year within the
3-year certification cycle); each :class:`AuditProgramItem` is a scheduled audit
of an area/process that links to a conducted :class:`Audit` once executed.
"""

from __future__ import annotations

import enum

from sqlalchemy import Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ProgramStatus(str, enum.Enum):
    DRAFT = "draft"
    ACTIVE = "active"
    CLOSED = "closed"


class ProgramItemStatus(str, enum.Enum):
    PLANNED = "planned"
    SCHEDULED = "scheduled"
    COMPLETED = "completed"
    CANCELLED = "cancelled"


class AuditProgram(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "audit_programs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    year: Mapped[int] = mapped_column(Integer, nullable=False, index=True)
    objectives: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[ProgramStatus] = mapped_column(
        Enum(ProgramStatus, name="audit_program_status"),
        default=ProgramStatus.DRAFT,
        nullable=False,
    )

    items: Mapped[list[AuditProgramItem]] = relationship(
        "AuditProgramItem",
        back_populates="program",
        cascade="all, delete-orphan",
        order_by="AuditProgramItem.id",
    )


class AuditProgramItem(Base, TimestampMixin):
    __tablename__ = "audit_program_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    program_id: Mapped[int] = mapped_column(
        ForeignKey("audit_programs.id", ondelete="CASCADE"), nullable=False, index=True
    )
    area: Mapped[str] = mapped_column(String(255), nullable=False)
    clause_reference: Mapped[str | None] = mapped_column(String(64), nullable=True)
    # Free-form planned period, e.g. "2026-Q1" or "2026-03".
    planned_period: Mapped[str | None] = mapped_column(String(32), nullable=True)
    lead_auditor_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    status: Mapped[ProgramItemStatus] = mapped_column(
        Enum(ProgramItemStatus, name="audit_program_item_status"),
        default=ProgramItemStatus.PLANNED,
        nullable=False,
    )
    # Set when this scheduled audit has been conducted.
    audit_id: Mapped[int | None] = mapped_column(ForeignKey("audits.id"), nullable=True)

    program: Mapped[AuditProgram] = relationship("AuditProgram", back_populates="items")
