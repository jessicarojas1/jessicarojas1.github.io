"""Counterfeit -> NCR linkage

Revision ID: 0013_counterfeit_ncr_link
Revises: 0012_apqp_ppap
Create Date: 2026-06-07

Adds a nullable ``ncr_id`` foreign key to ``part_sourcing_records`` and
``counterfeit_alerts`` so an NCR raised from a suspect part / alert links back
to its source. Idempotent and schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0013_counterfeit_ncr_link"
down_revision: str | tuple[str, ...] | None = "0012_apqp_ppap"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


_TABLES = ("part_sourcing_records", "counterfeit_alerts")


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    ncr = _qualify("nonconformances", schema)
    for tbl in _TABLES:
        t = _qualify(tbl, schema)
        bind.execute(text(f"ALTER TABLE {t} ADD COLUMN IF NOT EXISTS ncr_id INTEGER"))
        bind.execute(
            text(
                f"DO $$ BEGIN "
                f"IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_{tbl}_ncr') THEN "
                f"ALTER TABLE {t} ADD CONSTRAINT fk_{tbl}_ncr "
                f"FOREIGN KEY (ncr_id) REFERENCES {ncr} (id); "
                f"END IF; END $$;"
            )
        )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    for tbl in _TABLES:
        t = _qualify(tbl, schema)
        bind.execute(text(f"ALTER TABLE {t} DROP CONSTRAINT IF EXISTS fk_{tbl}_ncr"))
        bind.execute(text(f"ALTER TABLE {t} DROP COLUMN IF EXISTS ncr_id"))
