"""Lessons Learned schemas."""

from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.lesson import LessonCategory, LessonSource, LessonStatus
from app.schemas.common import ORMModel


class LessonBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    category: LessonCategory = LessonCategory.PROCESS
    source: LessonSource = LessonSource.OTHER
    source_ref: str | None = Field(default=None, max_length=64)
    department: str | None = Field(default=None, max_length=128)
    owner_id: int | None = None
    event_date: date | None = None
    what_happened: str | None = None
    root_cause: str | None = None
    recommendation: str | None = None


class LessonCreate(LessonBase):
    pass


class LessonUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    category: LessonCategory | None = None
    source: LessonSource | None = None
    source_ref: str | None = Field(default=None, max_length=64)
    status: LessonStatus | None = None
    department: str | None = Field(default=None, max_length=128)
    owner_id: int | None = None
    event_date: date | None = None
    what_happened: str | None = None
    root_cause: str | None = None
    recommendation: str | None = None


class LessonRead(ORMModel):
    id: int
    lesson_number: str
    title: str
    category: LessonCategory
    source: LessonSource
    source_ref: str | None
    status: LessonStatus
    department: str | None
    owner_id: int | None
    event_date: date | None
    what_happened: str | None
    root_cause: str | None
    recommendation: str | None
    published_at: datetime | None = None
    created_at: datetime | None = None
    updated_at: datetime | None = None
