"""Management review schemas."""
from __future__ import annotations

from datetime import date, datetime

from pydantic import BaseModel, Field

from app.models.mgmt_review import ActionItemStatus, ReviewStatus
from app.schemas.common import ORMModel


class ReviewInputCreate(BaseModel):
    category: str = Field(..., min_length=1, max_length=128)
    content: str = Field(..., min_length=1)
    metric_value: str | None = Field(default=None, max_length=128)


class ReviewInputRead(ORMModel):
    id: int
    review_id: int
    category: str
    content: str
    metric_value: str | None


class ActionItemCreate(BaseModel):
    description: str = Field(..., min_length=1)
    owner_id: int | None = None
    due_date: date | None = None


class ActionItemUpdate(BaseModel):
    description: str | None = None
    owner_id: int | None = None
    status: ActionItemStatus | None = None
    due_date: date | None = None
    completed_at: datetime | None = None


class ActionItemRead(ORMModel):
    id: int
    review_id: int | None
    description: str
    owner_id: int | None
    status: ActionItemStatus
    due_date: date | None
    completed_at: datetime | None
    created_at: datetime | None = None


class ReviewBase(BaseModel):
    title: str = Field(..., min_length=1, max_length=512)
    meeting_date: date | None = None
    attendees: str | None = None
    chairperson_id: int | None = None
    summary: str | None = None
    minutes: str | None = None


class ReviewCreate(ReviewBase):
    pass


class ReviewUpdate(BaseModel):
    title: str | None = Field(default=None, max_length=512)
    status: ReviewStatus | None = None
    meeting_date: date | None = None
    attendees: str | None = None
    chairperson_id: int | None = None
    summary: str | None = None
    minutes: str | None = None


class ReviewRead(ORMModel):
    id: int
    review_number: str
    title: str
    status: ReviewStatus
    meeting_date: date | None
    attendees: str | None
    chairperson_id: int | None
    summary: str | None
    minutes: str | None
    created_at: datetime | None = None
    updated_at: datetime | None = None
    inputs: list[ReviewInputRead] = []
    action_items: list[ActionItemRead] = []


class ReviewList(ORMModel):
    id: int
    review_number: str
    title: str
    status: ReviewStatus
    meeting_date: date | None
    created_at: datetime | None = None
