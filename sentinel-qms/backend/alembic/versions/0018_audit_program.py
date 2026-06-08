"""Internal audit program (annual schedule)

Revision ID: 0018_audit_program
Revises: 0017_customer_contracts
Create Date: 2026-06-08

Adds ``audit_programs`` and ``audit_program_items`` (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0018_audit_program"
down_revision: str | tuple[str, ...] | None = "0017_customer_contracts"
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

    prog = _qualify("audit_programs", schema)
    item = _qualify("audit_program_items", schema)
    audits = _qualify("audits", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {prog} (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                year INTEGER NOT NULL,
                objectives TEXT,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
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
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_audit_programs_year ON {prog} (year)"))

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {item} (
                id SERIAL PRIMARY KEY,
                program_id INTEGER NOT NULL REFERENCES {prog} (id) ON DELETE CASCADE,
                area VARCHAR(255) NOT NULL,
                clause_reference VARCHAR(64),
                planned_period VARCHAR(32),
                lead_auditor_id INTEGER,
                status VARCHAR(32) NOT NULL DEFAULT 'planned',
                audit_id INTEGER REFERENCES {audits} (id),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_audit_program_items_program ON {item} (program_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('audit_program_items', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('audit_programs', schema)}"))
