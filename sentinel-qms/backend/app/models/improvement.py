"""Continual Improvement / Kaizen register (AS9100/ISO 9001 clause 10.3).

An :class:`Improvement` is an improvement opportunity, suggestion or kaizen —
distinct from a CAPA (which is reactive to a nonconformity). It carries its own
lightweight idea→done workflow plus estimated and realized benefit so the QMS
can demonstrate continual improvement and quantify its impact.
"""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ImprovementCategory(str, enum.Enum):
    KAIZEN = "kaizen"
    SUGGESTION = "suggestion"
    PROCESS = "process"
    COST_SAVING = "cost_saving"
    SAFETY = "safety"
    QUALITY = "quality"


class ImprovementStatus(str, enum.Enum):
    IDEA = "idea"
    EVALUATING = "evaluating"
    IN_PROGRESS = "in_progress"
    DONE = "done"
    REJECTED = "rejected"


class ImprovementPriority(str, enum.Enum):
    LOW = "low"
    MEDIUM = "medium"
    HIGH = "high"


class Improvement(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "improvements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    improvement_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    category: Mapped[ImprovementCategory] = mapped_column(
        Enum(ImprovementCategory, name="improvement_category"),
        default=ImprovementCategory.KAIZEN,
        nullable=False,
    )
    source: Mapped[str | None] = mapped_column(String(255), nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    status: Mapped[ImprovementStatus] = mapped_column(
        Enum(ImprovementStatus, name="improvement_status"),
        default=ImprovementStatus.IDEA,
        nullable=False,
    )
    priority: Mapped[ImprovementPriority] = mapped_column(
        Enum(ImprovementPriority, name="improvement_priority"),
        default=ImprovementPriority.MEDIUM,
        nullable=False,
    )
    estimated_benefit: Mapped[float | None] = mapped_column(Float, nullable=True)
    realized_benefit: Mapped[float | None] = mapped_column(Float, nullable=True)
    target_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    clause_ref: Mapped[str | None] = mapped_column(String(64), nullable=True)
