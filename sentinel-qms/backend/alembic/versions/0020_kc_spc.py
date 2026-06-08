"""Key characteristics & SPC measurements

Revision ID: 0020_kc_spc
Revises: 0019_msa_studies
Create Date: 2026-06-08

Adds ``key_characteristics`` and ``kc_measurements`` (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0020_kc_spc"
down_revision: str | tuple[str, ...] | None = "0019_msa_studies"
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

    kc = _qualify("key_characteristics", schema)
    meas = _qualify("kc_measurements", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {kc} (
                id SERIAL PRIMARY KEY,
                kc_number VARCHAR(32) NOT NULL UNIQUE,
                part_number VARCHAR(128) NOT NULL,
                characteristic VARCHAR(255) NOT NULL,
                nominal DOUBLE PRECISION,
                usl DOUBLE PRECISION,
                lsl DOUBLE PRECISION,
                unit VARCHAR(32),
                kc_class VARCHAR(32) NOT NULL DEFAULT 'major',
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
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_key_characteristics_number ON {kc} (kc_number)")
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_key_characteristics_part ON {kc} (part_number)")
    )

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {meas} (
                id SERIAL PRIMARY KEY,
                kc_id INTEGER NOT NULL REFERENCES {kc} (id) ON DELETE CASCADE,
                value DOUBLE PRECISION NOT NULL,
                measured_at DATE,
                operator VARCHAR(128),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_kc_measurements_kc ON {meas} (kc_id)"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('kc_measurements', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('key_characteristics', schema)}"))
