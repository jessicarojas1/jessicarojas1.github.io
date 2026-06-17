"""Customer Satisfaction surveys (AS9100/ISO 9001 clause 9.1.2).

A :class:`CustomerSurvey` is a periodic satisfaction reading for a customer —
quality, on-time-delivery and communication sub-scores (0–100) plus an optional
overall. Proactive complement to the (reactive) complaints module; trended
per customer over time.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class SurveyMethod(str, enum.Enum):
    SURVEY = "survey"
    SCORECARD = "scorecard"
    PORTAL = "portal"
    MEETING = "meeting"


class CustomerSurvey(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "customer_surveys"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    survey_number: Mapped[str] = mapped_column(
        String(32), unique=True, nullable=False, index=True
    )
    customer_id: Mapped[int] = mapped_column(
        ForeignKey("customers.id"), nullable=False, index=True
    )
    period: Mapped[str | None] = mapped_column(String(32), nullable=True)
    survey_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    method: Mapped[SurveyMethod] = mapped_column(
        Enum(SurveyMethod, name="survey_method"),
        default=SurveyMethod.SURVEY,
        nullable=False,
    )
    quality_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    delivery_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    communication_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    overall_score: Mapped[float | None] = mapped_column(Float, nullable=True)
    respondent: Mapped[str | None] = mapped_column(String(255), nullable=True)
    comments: Mapped[str | None] = mapped_column(Text, nullable=True)
