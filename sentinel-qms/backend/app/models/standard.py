"""Standards & framework coverage mapping.

A ``Standard`` is a framework we verify against (AS9100D, ISO 9001, NADCAP,
NIST 800-171, …). Each ``StandardRequirement`` is one clause/control mapped to
the QMS module that provides its evidence, with a coverage status — so audit
readiness per framework can be shown at a glance.
"""

from __future__ import annotations

import enum

from sqlalchemy import Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin


class CoverageStatus(str, enum.Enum):
    COVERED = "covered"
    PARTIAL = "partial"
    GAP = "gap"
    NOT_APPLICABLE = "not_applicable"


class Standard(Base, TimestampMixin):
    __tablename__ = "standards"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(64), unique=True, nullable=False, index=True)
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    is_active: Mapped[bool] = mapped_column(default=True, nullable=False)

    requirements: Mapped[list[StandardRequirement]] = relationship(
        "StandardRequirement",
        back_populates="standard",
        cascade="all, delete-orphan",
        order_by="StandardRequirement.id",
    )


class StandardRequirement(Base, TimestampMixin):
    __tablename__ = "standard_requirements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    standard_id: Mapped[int] = mapped_column(
        ForeignKey("standards.id", ondelete="CASCADE"), nullable=False, index=True
    )
    clause: Mapped[str] = mapped_column(String(64), nullable=False)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    # The QMS module key that provides evidence (e.g. "audits", "suppliers").
    module_key: Mapped[str | None] = mapped_column(String(64), nullable=True)
    coverage_status: Mapped[CoverageStatus] = mapped_column(
        Enum(CoverageStatus, name="coverage_status"),
        default=CoverageStatus.GAP,
        nullable=False,
    )
    evidence_note: Mapped[str | None] = mapped_column(Text, nullable=True)

    standard: Mapped[Standard] = relationship("Standard", back_populates="requirements")
