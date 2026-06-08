"""Concession / Deviation / Waiver permits

Revision ID: 0015_concessions
Revises: 0014_fod_program
Create Date: 2026-06-08

Adds the ``concessions`` table (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0015_concessions"
down_revision: str | tuple[str, ...] | None = "0014_fod_program"
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

    tbl = _qualify("concessions", schema)
    suppliers = _qualify("suppliers", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                concession_number VARCHAR(32) NOT NULL UNIQUE,
                concession_type VARCHAR(32) NOT NULL DEFAULT 'deviation',
                title VARCHAR(512) NOT NULL,
                part_number VARCHAR(128),
                description TEXT NOT NULL,
                justification TEXT,
                quantity INTEGER,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                supplier_id INTEGER REFERENCES {suppliers} (id),
                customer_approval_required BOOLEAN NOT NULL DEFAULT FALSE,
                customer_approved BOOLEAN NOT NULL DEFAULT FALSE,
                expiry_date DATE,
                closed_at TIMESTAMPTZ,
                is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                deleted_at TIMESTAMPTZ,
                deleted_by INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_concessions_number ON {tbl} (concession_number)")
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_concessions_part ON {tbl} (part_number)"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('concessions', schema)}"))
