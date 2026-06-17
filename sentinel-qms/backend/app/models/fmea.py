"""FMEA — Failure Mode and Effects Analysis (PFMEA / DFMEA, AS9145 / AIAG-VDA).

An :class:`Fmea` is a worksheet header (process or design) owning a set of
:class:`FmeaItem` line items. Each item carries Severity, Occurrence and
Detection (1–10) whose product is the Risk Priority Number (RPN), with a
recommended action and owner — feeding the risk register and key characteristics.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class FmeaType(str, enum.Enum):
    PROCESS = "process"  # PFMEA
    DESIGN = "design"  # DFMEA


class FmeaStatus(str, enum.Enum):
    DRAFT = "draft"
    ACTIVE = "active"
    CLOSED = "closed"


class FmeaItemStatus(str, enum.Enum):
    OPEN = "open"
    ACTION_TAKEN = "action_taken"
    CLOSED = "closed"


class Fmea(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "fmeas"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fmea_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    fmea_type: Mapped[FmeaType] = mapped_column(
        Enum(FmeaType, name="fmea_type"), default=FmeaType.PROCESS, nullable=False
    )
    part_number: Mapped[str | None] = mapped_column(String(128), nullable=True, index=True)
    process_ref: Mapped[str | None] = mapped_column(String(255), nullable=True)
    scope: Mapped[str | None] = mapped_column(Text, nullable=True)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    status: Mapped[FmeaStatus] = mapped_column(
        Enum(FmeaStatus, name="fmea_status"), default=FmeaStatus.DRAFT, nullable=False
    )

    items: Mapped[list[FmeaItem]] = relationship(
        "FmeaItem",
        back_populates="fmea",
        cascade="all, delete-orphan",
        order_by="FmeaItem.id",
    )


class FmeaItem(Base, TimestampMixin):
    __tablename__ = "fmea_items"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fmea_id: Mapped[int] = mapped_column(
        ForeignKey("fmeas.id", ondelete="CASCADE"), nullable=False, index=True
    )
    function: Mapped[str] = mapped_column(String(512), nullable=False)
    failure_mode: Mapped[str] = mapped_column(String(512), nullable=False)
    effect: Mapped[str | None] = mapped_column(Text, nullable=True)
    cause: Mapped[str | None] = mapped_column(Text, nullable=True)
    controls: Mapped[str | None] = mapped_column(Text, nullable=True)
    severity: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    occurrence: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    detection: Mapped[int] = mapped_column(Integer, default=1, nullable=False)
    recommended_action: Mapped[str | None] = mapped_column(Text, nullable=True)
    action_owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    target_date: Mapped[date | None] = mapped_column(Date, nullable=True)
    status: Mapped[FmeaItemStatus] = mapped_column(
        Enum(FmeaItemStatus, name="fmea_item_status"),
        default=FmeaItemStatus.OPEN,
        nullable=False,
    )

    fmea: Mapped[Fmea] = relationship("Fmea", back_populates="items")
