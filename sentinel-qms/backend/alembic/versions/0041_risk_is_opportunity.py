"""Risk register opportunity flag

Revision ID: 0041_risk_is_opportunity
Revises: 0040_lessons_learned
Create Date: 2026-06-30

Adds ``risks.is_opportunity`` so the risk register can hold both risks and
opportunities (ISO 9001 6.1 — "actions to address risks AND opportunities").
Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0041_risk_is_opportunity"
down_revision: str | tuple[str, ...] | None = "0040_lessons_learned"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("risks", schema)
    bind.execute(
        text(
            f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS "
            "is_opportunity BOOLEAN NOT NULL DEFAULT FALSE"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("risks", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS is_opportunity"))
