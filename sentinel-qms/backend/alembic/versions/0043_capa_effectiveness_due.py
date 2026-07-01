"""CAPA effectiveness-verification due date

Revision ID: 0043_capa_effectiveness_due
Revises: 0042_capa_five_whys
Create Date: 2026-07-01

Adds ``capas.effectiveness_due_date`` so a CAPA can carry a scheduled date by
which its effectiveness must be verified. Overdue verifications escalate via the
existing SLA sweep. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0043_capa_effectiveness_due"
down_revision: str | tuple[str, ...] | None = "0042_capa_five_whys"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("capas", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS effectiveness_due_date DATE"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("capas", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS effectiveness_due_date"))
