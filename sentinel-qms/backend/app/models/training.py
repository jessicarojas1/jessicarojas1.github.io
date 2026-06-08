"""Training & competency: Personnel, TrainingCourse, TrainingRecord, CompetencyMatrixEntry."""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class TrainingStatus(str, enum.Enum):
    ASSIGNED = "assigned"
    IN_PROGRESS = "in_progress"
    COMPLETED = "completed"
    EXPIRED = "expired"
    WAIVED = "waived"


class CompetencyLevel(str, enum.Enum):
    NONE = "none"
    AWARENESS = "awareness"
    PRACTITIONER = "practitioner"
    EXPERT = "expert"
    TRAINER = "trainer"


# Reuse one Enum instance for both competency-level columns.
COMPETENCY_LEVEL_ENUM = Enum(CompetencyLevel, name="competency_level")


class Personnel(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "personnel"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    employee_id: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    full_name: Mapped[str] = mapped_column(String(255), nullable=False)
    job_title: Mapped[str | None] = mapped_column(String(255), nullable=True)
    department: Mapped[str | None] = mapped_column(String(128), nullable=True)
    hire_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    is_active: Mapped[bool] = mapped_column(default=True, nullable=False)

    training_records: Mapped[list[TrainingRecord]] = relationship(
        "TrainingRecord", back_populates="personnel", cascade="all, delete-orphan"
    )
    competencies: Mapped[list[CompetencyMatrixEntry]] = relationship(
        "CompetencyMatrixEntry", back_populates="personnel", cascade="all, delete-orphan"
    )


class TrainingCourse(Base, TimestampMixin):
    __tablename__ = "training_courses"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    course_code: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(255), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    category: Mapped[str | None] = mapped_column(String(128), nullable=True)
    validity_months: Mapped[int | None] = mapped_column(Integer, nullable=True)
    is_mandatory: Mapped[bool] = mapped_column(default=False, nullable=False)

    records: Mapped[list[TrainingRecord]] = relationship(
        "TrainingRecord", back_populates="course", cascade="all, delete-orphan"
    )


class TrainingRecord(Base, TimestampMixin):
    __tablename__ = "training_records"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    personnel_id: Mapped[int] = mapped_column(
        ForeignKey("personnel.id", ondelete="CASCADE"), nullable=False, index=True
    )
    course_id: Mapped[int] = mapped_column(
        ForeignKey("training_courses.id", ondelete="CASCADE"), nullable=False, index=True
    )
    status: Mapped[TrainingStatus] = mapped_column(
        Enum(TrainingStatus, name="training_status"),
        default=TrainingStatus.ASSIGNED,
        nullable=False,
    )
    assigned_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    completion_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    expiry_date: Mapped[date | None] = mapped_column(Date, nullable=True, index=True)
    score: Mapped[str | None] = mapped_column(String(32), nullable=True)
    trainer: Mapped[str | None] = mapped_column(String(255), nullable=True)
    attachment_id: Mapped[int | None] = mapped_column(ForeignKey("attachments.id"), nullable=True)

    personnel: Mapped[Personnel] = relationship("Personnel", back_populates="training_records")
    course: Mapped[TrainingCourse] = relationship("TrainingCourse", back_populates="records")


class CompetencyMatrixEntry(Base, TimestampMixin):
    __tablename__ = "competency_matrix"
    __table_args__ = (UniqueConstraint("personnel_id", "skill", name="uq_competency_person_skill"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    personnel_id: Mapped[int] = mapped_column(
        ForeignKey("personnel.id", ondelete="CASCADE"), nullable=False, index=True
    )
    skill: Mapped[str] = mapped_column(String(255), nullable=False)
    required_level: Mapped[CompetencyLevel] = mapped_column(
        COMPETENCY_LEVEL_ENUM,
        default=CompetencyLevel.AWARENESS,
        nullable=False,
    )
    current_level: Mapped[CompetencyLevel] = mapped_column(
        COMPETENCY_LEVEL_ENUM,
        default=CompetencyLevel.NONE,
        nullable=False,
    )
    assessed_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    personnel: Mapped[Personnel] = relationship("Personnel", back_populates="competencies")
