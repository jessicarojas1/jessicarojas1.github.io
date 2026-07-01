"""CAPA structured 5-Why root-cause worksheet

Revision ID: 0042_capa_five_whys
Revises: 0041_risk_is_opportunity
Create Date: 2026-07-01

Adds ``capas.five_whys`` (JSONB on Postgres) storing an ordered list of
``{"why": str, "because": str}`` steps so the 5-Why root-cause chain is
queryable and displayable. Augments — does not replace — the existing
free-text ``d4_root_cause`` / ``root_cause_method`` fields. Idempotent +
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0042_capa_five_whys"
down_revision: str | tuple[str, ...] | None = "0041_risk_is_opportunity"
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
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS five_whys JSONB"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("capas", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS five_whys"))
