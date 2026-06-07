"""SLA escalation ledger — one row per (entity, level) escalation already fired.

The SLA sweep (``app.services.sla``) consults and writes this table so each
record is escalated at most once per level. The unique constraint on
``(entity_type, entity_id, level)`` also makes the sweep race-safe across
multiple web workers: the worker that wins the INSERT owns the escalation and
sends the notification; a concurrent worker hits the constraint and skips.
"""

from __future__ import annotations

from datetime import datetime

from sqlalchemy import DateTime, Integer, String, UniqueConstraint, func
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class SlaEscalation(Base):
    __tablename__ = "sla_escalations"
    __table_args__ = (
        UniqueConstraint(
            "entity_type", "entity_id", "level", name="uq_sla_escalation_entity_level"
        ),
    )

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    # e.g. "capa", "capa_action", "nonconformance".
    entity_type: Mapped[str] = mapped_column(String(64), nullable=False)
    entity_id: Mapped[str] = mapped_column(String(64), nullable=False)
    # e.g. "due_soon", "overdue".
    level: Mapped[str] = mapped_column(String(32), nullable=False)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), nullable=False
    )
