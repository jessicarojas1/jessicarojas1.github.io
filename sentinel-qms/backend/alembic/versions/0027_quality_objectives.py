"""Quality Objectives & KPIs

Revision ID: 0027_quality_objectives
Revises: 0026_standards_authorship
Create Date: 2026-06-16

Adds the ``quality_objectives`` and ``quality_objective_measurements`` tables
backing the AS9100/ISO 9001 clause 6.2 objectives & KPI module. Idempotent +
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0027_quality_objectives"
down_revision: str | tuple[str, ...] | None = "0026_standards_authorship"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    obj = _qualify("quality_objectives", schema)
    meas = _qualify("quality_objective_measurements", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {obj} (
                id SERIAL PRIMARY KEY,
                objective_number VARCHAR(32) NOT NULL UNIQUE,
                title VARCHAR(512) NOT NULL,
                description TEXT,
                category VARCHAR(128),
                owner_id INTEGER REFERENCES {users} (id),
                target_value DOUBLE PRECISION NOT NULL,
                baseline_value DOUBLE PRECISION,
                current_value DOUBLE PRECISION,
                unit VARCHAR(32),
                direction VARCHAR(16) NOT NULL DEFAULT 'higher_better',
                cadence VARCHAR(16) NOT NULL DEFAULT 'quarterly',
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                target_date DATE,
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
        text(f"CREATE INDEX IF NOT EXISTS ix_quality_objectives_number ON {obj} (objective_number)")
    )
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {meas} (
                id SERIAL PRIMARY KEY,
                objective_id INTEGER NOT NULL REFERENCES {obj} (id) ON DELETE CASCADE,
                value DOUBLE PRECISION NOT NULL,
                measured_at DATE,
                note VARCHAR(512),
                recorded_at TIMESTAMPTZ,
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
            "CREATE INDEX IF NOT EXISTS ix_quality_objective_measurements_objective_id "
            f"ON {meas} (objective_id)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('quality_objective_measurements', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('quality_objectives', schema)}"))
