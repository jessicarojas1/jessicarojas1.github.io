"""SCAR -> CAPA link

Revision ID: 0016_scar_capa_link
Revises: 0015_concessions
Create Date: 2026-06-08

Adds a nullable ``capa_id`` foreign key to ``supplier_scars`` so a CAPA raised
from a SCAR links back. Idempotent and schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0016_scar_capa_link"
down_revision: str | tuple[str, ...] | None = "0015_concessions"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("supplier_scars", schema)
    capas = _qualify("capas", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS capa_id INTEGER"))
    bind.execute(
        text(
            "DO $$ BEGIN "
            "IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_supplier_scars_capa') "
            f"THEN ALTER TABLE {tbl} ADD CONSTRAINT fk_supplier_scars_capa "
            f"FOREIGN KEY (capa_id) REFERENCES {capas} (id); END IF; END $$;"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("supplier_scars", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP CONSTRAINT IF EXISTS fk_supplier_scars_capa"))
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS capa_id"))
