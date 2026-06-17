"""Customer Satisfaction surveys

Revision ID: 0029_customer_surveys
Revises: 0028_improvements
Create Date: 2026-06-17

Adds the ``customer_surveys`` table backing the AS9100/ISO 9001 clause 9.1.2
customer-satisfaction module. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0029_customer_surveys"
down_revision: str | tuple[str, ...] | None = "0028_improvements"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("customer_surveys", schema)
    customers = _qualify("customers", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tbl} (
                id SERIAL PRIMARY KEY,
                survey_number VARCHAR(32) NOT NULL UNIQUE,
                customer_id INTEGER NOT NULL REFERENCES {customers} (id),
                period VARCHAR(32),
                survey_date DATE,
                method VARCHAR(16) NOT NULL DEFAULT 'survey',
                quality_score DOUBLE PRECISION,
                delivery_score DOUBLE PRECISION,
                communication_score DOUBLE PRECISION,
                overall_score DOUBLE PRECISION,
                respondent VARCHAR(255),
                comments TEXT,
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
        text(f"CREATE INDEX IF NOT EXISTS ix_customer_surveys_customer_id ON {tbl} (customer_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('customer_surveys', schema)}"))
