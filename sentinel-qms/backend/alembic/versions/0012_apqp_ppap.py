"""APQP / PPAP (AS9145)

Revision ID: 0012_apqp_ppap
Revises: 0011_counterfeit_parts
Create Date: 2026-06-07

Adds the ``apqp_projects`` and ``ppap_elements`` tables (idempotent,
schema-aware). Enum-like columns are stored as VARCHAR for portability.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0012_apqp_ppap"
down_revision: str | tuple[str, ...] | None = "0011_counterfeit_parts"
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

    proj = _qualify("apqp_projects", schema)
    elem = _qualify("ppap_elements", schema)
    suppliers = _qualify("suppliers", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {proj} (
                id SERIAL PRIMARY KEY,
                project_number VARCHAR(32) NOT NULL UNIQUE,
                part_number VARCHAR(128) NOT NULL,
                part_name VARCHAR(255) NOT NULL,
                customer VARCHAR(255),
                supplier_id INTEGER REFERENCES {suppliers} (id),
                current_phase VARCHAR(32) NOT NULL DEFAULT 'planning',
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                submission_level INTEGER NOT NULL DEFAULT 3,
                target_date DATE,
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
        text(f"CREATE INDEX IF NOT EXISTS ix_apqp_projects_part_number ON {proj} (part_number)")
    )
    bind.execute(
        text(
            f"CREATE INDEX IF NOT EXISTS ix_apqp_projects_project_number ON {proj} (project_number)"
        )
    )

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {elem} (
                id SERIAL PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES {proj} (id) ON DELETE CASCADE,
                element_key VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'not_started',
                notes TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_ppap_elements_project_id ON {elem} (project_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('ppap_elements', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('apqp_projects', schema)}"))
