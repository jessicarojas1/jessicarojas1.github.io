"""Lessons Learned registry (organizational learning / knowledge retention).

A :class:`LessonLearned` is a formal, reusable lesson captured from an event —
an NCR, CAPA, audit finding, complaint, project milestone, or incident — distinct
from a continual-improvement idea (which is forward-looking and benefit-driven).
Lessons are searchable institutional memory: what happened, why, and the
recommendation to repeat the good or avoid the bad.
"""

from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class LessonCategory(str, enum.Enum):
    PROCESS = "process"
    QUALITY = "quality"
    SUPPLIER = "supplier"
    DESIGN = "design"
    SAFETY = "safety"
    PROJECT = "project"
    OTHER = "other"


class LessonSource(str, enum.Enum):
    NCR = "ncr"
    CAPA = "capa"
    AUDIT = "audit"
    COMPLAINT = "complaint"
    PROJECT = "project"
    INCIDENT = "incident"
    CUSTOMER = "customer"
    OTHER = "other"


class LessonStatus(str, enum.Enum):
    DRAFT = "draft"
    PUBLISHED = "published"
    ARCHIVED = "archived"


class LessonLearned(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "lessons_learned"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    lesson_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    category: Mapped[LessonCategory] = mapped_column(
        Enum(LessonCategory, name="lesson_category"),
        default=LessonCategory.PROCESS,
        nullable=False,
    )
    source: Mapped[LessonSource] = mapped_column(
        Enum(LessonSource, name="lesson_source"),
        default=LessonSource.OTHER,
        nullable=False,
    )
    # Free-text reference to the originating record (e.g. "NCR-2026-0007").
    source_ref: Mapped[str | None] = mapped_column(String(64), nullable=True)
    status: Mapped[LessonStatus] = mapped_column(
        Enum(LessonStatus, name="lesson_status"),
        default=LessonStatus.DRAFT,
        nullable=False,
    )
    department: Mapped[str | None] = mapped_column(String(128), nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    event_date: Mapped[date | None] = mapped_column(Date, nullable=True)

    # Narrative fields.
    what_happened: Mapped[str | None] = mapped_column(Text, nullable=True)
    root_cause: Mapped[str | None] = mapped_column(Text, nullable=True)
    recommendation: Mapped[str | None] = mapped_column(Text, nullable=True)

    published_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
