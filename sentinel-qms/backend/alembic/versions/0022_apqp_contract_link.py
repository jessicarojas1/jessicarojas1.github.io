"""APQP -> contract link

Revision ID: 0022_apqp_contract_link
Revises: 0021_saved_views
Create Date: 2026-06-08

Adds a nullable ``contract_id`` FK to ``apqp_projects``. Idempotent and
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0022_apqp_contract_link"
down_revision: str | tuple[str, ...] | None = "0021_saved_views"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("apqp_projects", schema)
    contracts = _qualify("contracts", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS contract_id INTEGER"))
    bind.execute(
        text(
            "DO $$ BEGIN "
            "IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_apqp_contract') "
            f"THEN ALTER TABLE {tbl} ADD CONSTRAINT fk_apqp_contract "
            f"FOREIGN KEY (contract_id) REFERENCES {contracts} (id); END IF; END $$;"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("apqp_projects", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP CONSTRAINT IF EXISTS fk_apqp_contract"))
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS contract_id"))
