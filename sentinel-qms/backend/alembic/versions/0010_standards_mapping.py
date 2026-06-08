"""Standards & framework coverage mapping

Revision ID: 0010_standards_mapping
Revises: 0009_exec_kpi_targets_coq_costs
Create Date: 2026-06-07

Adds the ``standards`` and ``standard_requirements`` tables (idempotent,
schema-aware) backing the standards-coverage matrix.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0010_standards_mapping"
down_revision: str | tuple[str, ...] | None = "0009_exec_kpi_targets_coq_costs"
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

    std = _qualify("standards", schema)
    req = _qualify("standard_requirements", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {std} (
                id SERIAL PRIMARY KEY,
                code VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_standards_code ON {std} (code)"))

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {req} (
                id SERIAL PRIMARY KEY,
                standard_id INTEGER NOT NULL REFERENCES {std} (id) ON DELETE CASCADE,
                clause VARCHAR(64) NOT NULL,
                title VARCHAR(512) NOT NULL,
                module_key VARCHAR(64),
                coverage_status VARCHAR(32) NOT NULL DEFAULT 'gap',
                evidence_note TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_standard_requirements_standard_id "
            f"ON {req} (standard_id)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('standard_requirements', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('standards', schema)}"))
