"""Measurement Systems Analysis (MSA) — Gage R&R and related studies.

Records a measurement-system study (typically against a piece of calibrated
equipment), capturing %GR&R and the number of distinct categories (ndc) with an
AIAG-style acceptability result.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class MsaType(str, enum.Enum):
    GAGE_RR = "gage_rr"
    BIAS = "bias"
    LINEARITY = "linearity"
    STABILITY = "stability"


class MsaResult(str, enum.Enum):
    ACCEPTABLE = "acceptable"
    MARGINAL = "marginal"
    UNACCEPTABLE = "unacceptable"
    PENDING = "pending"


class MsaStudy(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "msa_studies"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    study_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    equipment_id: Mapped[int | None] = mapped_column(ForeignKey("equipment.id"), nullable=True)
    characteristic: Mapped[str] = mapped_column(String(255), nullable=False)
    study_type: Mapped[MsaType] = mapped_column(
        Enum(MsaType, name="msa_type"), default=MsaType.GAGE_RR, nullable=False
    )
    num_parts: Mapped[int | None] = mapped_column(Integer, nullable=True)
    num_operators: Mapped[int | None] = mapped_column(Integer, nullable=True)
    num_trials: Mapped[int | None] = mapped_column(Integer, nullable=True)
    grr_percent: Mapped[float | None] = mapped_column(Numeric(6, 2), nullable=True)
    ndc: Mapped[int | None] = mapped_column(Integer, nullable=True)
    result: Mapped[MsaResult] = mapped_column(
        Enum(MsaResult, name="msa_result"), default=MsaResult.PENDING, nullable=False
    )
    study_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
