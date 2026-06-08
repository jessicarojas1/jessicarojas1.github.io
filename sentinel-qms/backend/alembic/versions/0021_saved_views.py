"""Per-user saved list views

Revision ID: 0021_saved_views
Revises: 0020_kc_spc
Create Date: 2026-06-08

Adds the ``saved_views`` table (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0021_saved_views"
down_revision: str | tuple[str, ...] | None = "0020_kc_spc"
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

    tbl = _qualify("saved_views", schema)
    users = _qualify("users", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                page_key VARCHAR(64) NOT NULL,
                name VARCHAR(128) NOT NULL,
                params TEXT NOT NULL DEFAULT '{{}}',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_saved_views_user_page ON {tbl} (user_id, page_key)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('saved_views', schema)}"))
