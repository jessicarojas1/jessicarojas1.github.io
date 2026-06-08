"""Record shares (Shared with Me)

Revision ID: 0023_record_shares
Revises: 0022_apqp_contract_link
Create Date: 2026-06-08

Adds the ``record_shares`` table (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0023_record_shares"
down_revision: str | tuple[str, ...] | None = "0022_apqp_contract_link"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    tbl = _qualify("record_shares", schema)
    users = _qualify("users", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                label VARCHAR(512) NOT NULL,
                shared_with_user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                shared_by_user_id INTEGER NOT NULL REFERENCES {users} (id),
                note TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(
            f"CREATE INDEX IF NOT EXISTS ix_record_shares_recipient ON {tbl} (shared_with_user_id)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('record_shares', schema)}"))
