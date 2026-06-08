"""MSA / Gage R&R studies

Revision ID: 0019_msa_studies
Revises: 0018_audit_program
Create Date: 2026-06-08

Adds the ``msa_studies`` table (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0019_msa_studies"
down_revision: str | tuple[str, ...] | None = "0018_audit_program"
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

    tbl = _qualify("msa_studies", schema)
    equipment = _qualify("equipment", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                study_number VARCHAR(32) NOT NULL UNIQUE,
                equipment_id INTEGER REFERENCES {equipment} (id),
                characteristic VARCHAR(255) NOT NULL,
                study_type VARCHAR(32) NOT NULL DEFAULT 'gage_rr',
                num_parts INTEGER,
                num_operators INTEGER,
                num_trials INTEGER,
                grr_percent NUMERIC(6, 2),
                ndc INTEGER,
                result VARCHAR(32) NOT NULL DEFAULT 'pending',
                study_date DATE,
                notes TEXT,
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
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_msa_studies_number ON {tbl} (study_number)"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('msa_studies', schema)}"))
