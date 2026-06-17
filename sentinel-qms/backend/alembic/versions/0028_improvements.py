"""Continual Improvement / Kaizen register

Revision ID: 0028_improvements
Revises: 0027_quality_objectives
Create Date: 2026-06-17

Adds the ``improvements`` table backing the AS9100/ISO 9001 clause 10.3
continual-improvement / kaizen module. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0028_improvements"
down_revision: str | tuple[str, ...] | None = "0027_quality_objectives"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("improvements", schema)
    users = _qualify("users", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                improvement_number VARCHAR(32) NOT NULL UNIQUE,
                title VARCHAR(512) NOT NULL,
                description TEXT,
                category VARCHAR(20) NOT NULL DEFAULT 'kaizen',
                source VARCHAR(255),
                owner_id INTEGER REFERENCES {users} (id),
                status VARCHAR(20) NOT NULL DEFAULT 'idea',
                priority VARCHAR(10) NOT NULL DEFAULT 'medium',
                estimated_benefit DOUBLE PRECISION,
                realized_benefit DOUBLE PRECISION,
                target_date DATE,
                completed_at TIMESTAMPTZ,
                clause_ref VARCHAR(64),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER,
                is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                deleted_at TIMESTAMPTZ,
                deleted_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_improvements_number ON {tbl} (improvement_number)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('improvements', schema)}"))
