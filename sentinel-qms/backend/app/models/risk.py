"""Risk register: Risk with severity / likelihood / RPN and treatment."""
from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class RiskStatus(str, enum.Enum):
    IDENTIFIED = "identified"
    ASSESSED = "assessed"
    TREATMENT_PLANNED = "treatment_planned"
    MITIGATING = "mitigating"
    MONITORING = "monitoring"
    CLOSED = "closed"


class RiskCategory(str, enum.Enum):
    QUALITY = "quality"
    SUPPLY_CHAIN = "supply_chain"
    OPERATIONAL = "operational"
    COMPLIANCE = "compliance"
    SAFETY = "safety"
    CYBERSECURITY = "cybersecurity"
    PROGRAM = "program"


class TreatmentStrategy(str, enum.Enum):
    AVOID = "avoid"
    MITIGATE = "mitigate"
    TRANSFER = "transfer"
    ACCEPT = "accept"


class Risk(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "risks"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    risk_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    category: Mapped[RiskCategory] = mapped_column(
        Enum(RiskCategory, name="risk_category"),
        default=RiskCategory.QUALITY,
        nullable=False,
    )
    status: Mapped[RiskStatus] = mapped_column(
        Enum(RiskStatus, name="risk_status"), default=RiskStatus.IDENTIFIED, nullable=False
    )
    description: Mapped[str] = mapped_column(Text, nullable=False)

    # RPN inputs (1–10 scales) and computed product.
    severity: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    likelihood: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    detectability: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    rpn: Mapped[int] = mapped_column(Integer, default=1, nullable=False, index=True)

    treatment_strategy: Mapped[TreatmentStrategy | None] = mapped_column(
        Enum(TreatmentStrategy, name="treatment_strategy"), nullable=True
    )
    treatment_plan: Mapped[str | None] = mapped_column(Text, nullable=True)

    # Residual after treatment.
    residual_severity: Mapped[int | None] = mapped_column(Integer, nullable=True)
    residual_likelihood: Mapped[int | None] = mapped_column(Integer, nullable=True)
    residual_detectability: Mapped[int | None] = mapped_column(Integer, nullable=True)
    residual_rpn: Mapped[int | None] = mapped_column(Integer, nullable=True)

    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    review_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    capa_id: Mapped[int | None] = mapped_column(ForeignKey("capas.id"), nullable=True)
