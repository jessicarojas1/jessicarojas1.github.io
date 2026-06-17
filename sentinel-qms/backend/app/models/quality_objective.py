"""Quality Objectives & KPIs (AS9100/ISO 9001 clause 6.2).

A :class:`QualityObjective` is a measurable quality goal with a target, an owner
and a cadence; :class:`QualityObjectiveMeasurement` rows are the periodic actuals
that drive attainment, RAG status and trend. Feeds management review (clause 9.3)
and the executive dashboard.
"""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ObjectiveDirection(str, enum.Enum):
    """Whether a higher or a lower measured value is better vs the target."""

    HIGHER_BETTER = "higher_better"
    LOWER_BETTER = "lower_better"


class ObjectiveCadence(str, enum.Enum):
    MONTHLY = "monthly"
    QUARTERLY = "quarterly"
    ANNUAL = "annual"


class ObjectiveStatus(str, enum.Enum):
    ACTIVE = "active"
    MET = "met"
    AT_RISK = "at_risk"
    MISSED = "missed"
    ARCHIVED = "archived"


class QualityObjective(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "quality_objectives"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    objective_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    category: Mapped[str | None] = mapped_column(String(128), nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)

    target_value: Mapped[float] = mapped_column(Float, nullable=False)
    baseline_value: Mapped[float | None] = mapped_column(Float, nullable=True)
    current_value: Mapped[float | None] = mapped_column(Float, nullable=True)
    unit: Mapped[str | None] = mapped_column(String(32), nullable=True)
    direction: Mapped[ObjectiveDirection] = mapped_column(
        Enum(ObjectiveDirection, name="objective_direction"),
        default=ObjectiveDirection.HIGHER_BETTER,
        nullable=False,
    )
    cadence: Mapped[ObjectiveCadence] = mapped_column(
        Enum(ObjectiveCadence, name="objective_cadence"),
        default=ObjectiveCadence.QUARTERLY,
        nullable=False,
    )
    status: Mapped[ObjectiveStatus] = mapped_column(
        Enum(ObjectiveStatus, name="objective_status"),
        default=ObjectiveStatus.ACTIVE,
        nullable=False,
    )
    target_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    clause_ref: Mapped[str | None] = mapped_column(String(64), nullable=True)

    measurements: Mapped[list[QualityObjectiveMeasurement]] = relationship(
        "QualityObjectiveMeasurement",
        back_populates="objective",
        cascade="all, delete-orphan",
        order_by="QualityObjectiveMeasurement.id",
    )


class QualityObjectiveMeasurement(Base, TimestampMixin):
    __tablename__ = "quality_objective_measurements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    objective_id: Mapped[int] = mapped_column(
        ForeignKey("quality_objectives.id", ondelete="CASCADE"), nullable=False, index=True
    )
    value: Mapped[float] = mapped_column(Float, nullable=False)
    measured_at: Mapped[date | None] = mapped_column(Date, nullable=True)
    note: Mapped[str | None] = mapped_column(String(512), nullable=True)
    recorded_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=True
    )

    objective: Mapped[QualityObjective] = relationship(
        "QualityObjective", back_populates="measurements"
    )
