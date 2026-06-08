"""Key Characteristic owner

Revision ID: 0024_kc_owner
Revises: 0023_record_shares
Create Date: 2026-06-08

Adds a nullable ``owner_id`` FK to ``key_characteristics`` so the person
responsible for a key characteristic can be notified when a new SPC
control-chart violation appears. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0024_kc_owner"
down_revision: str | tuple[str, ...] | None = "0023_record_shares"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("key_characteristics", schema)
    users = _qualify("users", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS owner_id INTEGER"))
    # Add the FK only if it is not already present (constraint name is stable).
    bind.execute(
        text(
            "DO $$ BEGIN "
            "IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_kc_owner') "
            f"THEN ALTER TABLE {tbl} ADD CONSTRAINT fk_kc_owner "
            f"FOREIGN KEY (owner_id) REFERENCES {users} (id); END IF; END $$;"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("key_characteristics", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP CONSTRAINT IF EXISTS fk_kc_owner"))
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS owner_id"))
