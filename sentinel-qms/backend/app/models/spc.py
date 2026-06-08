"""Key Characteristics (KC) and SPC measurement data.

A :class:`KeyCharacteristic` defines a controlled feature with spec limits;
:class:`Measurement` rows are the variable-data points used to compute process
capability (Cp/Cpk) and drive control charts.
"""

from __future__ import annotations

import enum
from datetime import date

from sqlalchemy import Date, Enum, Float, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import SoftDeleteMixin, TimestampMixin


class KcClass(str, enum.Enum):
    CRITICAL = "critical"
    MAJOR = "major"
    MINOR = "minor"


class KeyCharacteristic(Base, TimestampMixin, SoftDeleteMixin):
    __tablename__ = "key_characteristics"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    kc_number: Mapped[str] = mapped_column(String(32), unique=True, nullable=False, index=True)
    part_number: Mapped[str] = mapped_column(String(128), nullable=False, index=True)
    characteristic: Mapped[str] = mapped_column(String(255), nullable=False)
    nominal: Mapped[float | None] = mapped_column(Float, nullable=True)
    usl: Mapped[float | None] = mapped_column(Float, nullable=True)
    lsl: Mapped[float | None] = mapped_column(Float, nullable=True)
    unit: Mapped[str | None] = mapped_column(String(32), nullable=True)
    kc_class: Mapped[KcClass] = mapped_column(
        Enum(KcClass, name="kc_class"), default=KcClass.MAJOR, nullable=False
    )
    notes: Mapped[str | None] = mapped_column(Text, nullable=True)
    # Owner notified when a new control-chart violation appears.
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)

    measurements: Mapped[list[Measurement]] = relationship(
        "Measurement",
        back_populates="kc",
        cascade="all, delete-orphan",
        order_by="Measurement.id",
    )


class Measurement(Base, TimestampMixin):
    __tablename__ = "kc_measurements"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    kc_id: Mapped[int] = mapped_column(
        ForeignKey("key_characteristics.id", ondelete="CASCADE"), nullable=False, index=True
    )
    value: Mapped[float] = mapped_column(Float, nullable=False)
    measured_at: Mapped[date | None] = mapped_column(Date, nullable=True)
    operator: Mapped[str | None] = mapped_column(String(128), nullable=True)

    kc: Mapped[KeyCharacteristic] = relationship("KeyCharacteristic", back_populates="measurements")
