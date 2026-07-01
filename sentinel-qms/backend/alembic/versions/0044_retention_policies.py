"""Records Retention & Disposition Schedule

Revision ID: 0044_retention_policies
Revises: 0043_capa_effectiveness_due
Create Date: 2026-07-01

Adds the ``retention_policies`` table backing the Records Retention & Disposition
Schedule module: a documented retention rule per record category, the event that
starts the retention clock, the retention period, the *scheduled* (manual, not
automated) disposition action, and a legal-hold flag that suspends disposition.
Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0044_retention_policies"
down_revision: str | tuple[str, ...] | None = "0043_capa_effectiveness_due"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    policies = _qualify("retention_policies", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {policies} (
                id SERIAL PRIMARY KEY,
                policy_number VARCHAR(32) NOT NULL UNIQUE,
                title VARCHAR(512) NOT NULL,
                record_category VARCHAR(32) NOT NULL DEFAULT 'other',
                retention_trigger VARCHAR(16) NOT NULL DEFAULT 'creation',
                retention_years INTEGER,
                disposition_action VARCHAR(16) NOT NULL DEFAULT 'review',
                legal_hold BOOLEAN NOT NULL DEFAULT FALSE,
                authority_reference VARCHAR(255),
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                owner_id INTEGER REFERENCES {users} (id),
                notes TEXT,
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
        text(
            "CREATE INDEX IF NOT EXISTS ix_retention_policies_policy_number "
            f"ON {policies} (policy_number)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('retention_policies', schema)}"))
