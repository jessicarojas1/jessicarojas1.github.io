"""Management review: ManagementReview, ManagementReviewInput, ActionItem."""
from __future__ import annotations

import enum
from datetime import date, datetime

from sqlalchemy import Date, DateTime, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class ReviewStatus(str, enum.Enum):
    SCHEDULED = "scheduled"
    IN_PROGRESS = "in_progress"
    COMPLETED = "completed"
    CLOSED = "closed"


class ActionItemStatus(str, enum.Enum):
    OPEN = "open"
    IN_PROGRESS = "in_progress"
    COMPLETED = "completed"
    OVERDUE = "overdue"


class ManagementReview(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "management_reviews"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    review_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    status: Mapped[ReviewStatus] = mapped_column(
        Enum(ReviewStatus, name="review_status"), default=ReviewStatus.SCHEDULED, nullable=False
    )
    meeting_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    attendees: Mapped[str | None] = mapped_column(Text, nullable=True)
    chairperson_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    minutes: Mapped[str | None] = mapped_column(Text, nullable=True)

    inputs: Mapped[list[ManagementReviewInput]] = relationship(
        "ManagementReviewInput", back_populates="review", cascade="all, delete-orphan"
    )
    action_items: Mapped[list[ActionItem]] = relationship(
        "ActionItem", back_populates="review", cascade="all, delete-orphan"
    )


class ManagementReviewInput(Base):
    __tablename__ = "management_review_inputs"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    review_id: Mapped[int] = mapped_column(
        ForeignKey("management_reviews.id", ondelete="CASCADE"), nullable=False, index=True
    )
    category: Mapped[str] = mapped_column(String(128), nullable=False)  # audit results, NCR trends...
    content: Mapped[str] = mapped_column(Text, nullable=False)
    metric_value: Mapped[str | None] = mapped_column(String(128), nullable=True)

    review: Mapped[ManagementReview] = relationship("ManagementReview", back_populates="inputs")


class ActionItem(Base, TimestampMixin):
    __tablename__ = "action_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    review_id: Mapped[int | None] = mapped_column(
        ForeignKey("management_reviews.id", ondelete="CASCADE"), nullable=True, index=True
    )
    description: Mapped[str] = mapped_column(Text, nullable=False)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    status: Mapped[ActionItemStatus] = mapped_column(
        Enum(ActionItemStatus, name="action_item_status"),
        default=ActionItemStatus.OPEN,
        nullable=False,
    )
    due_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    review: Mapped[ManagementReview | None] = relationship(
        "ManagementReview", back_populates="action_items"
    )
