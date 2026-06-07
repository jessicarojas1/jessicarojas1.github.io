"""Counterfeit-parts prevention (AS5553/AS6081)

Revision ID: 0011_counterfeit_parts
Revises: 0010_standards_mapping
Create Date: 2026-06-07

Adds the ``part_sourcing_records`` and ``counterfeit_alerts`` tables
(idempotent, schema-aware). Enum-like columns are stored as VARCHAR for
portability, matching the rest of the schema.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0011_counterfeit_parts"
down_revision: str | tuple[str, ...] | None = "0010_standards_mapping"
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

    src = _qualify("part_sourcing_records", schema)
    alerts = _qualify("counterfeit_alerts", schema)
    suppliers = _qualify("suppliers", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {src} (
                id SERIAL PRIMARY KEY,
                record_number VARCHAR(32) NOT NULL UNIQUE,
                part_number VARCHAR(128) NOT NULL,
                description VARCHAR(512),
                supplier_id INTEGER REFERENCES {suppliers} (id),
                source_type VARCHAR(32) NOT NULL DEFAULT 'ocm',
                lot_date_code VARCHAR(128),
                quantity INTEGER,
                coc_received BOOLEAN NOT NULL DEFAULT FALSE,
                traceability_to_oem BOOLEAN NOT NULL DEFAULT FALSE,
                inspection_method VARCHAR(255),
                risk_level VARCHAR(32) NOT NULL DEFAULT 'medium',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
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
        text(f"CREATE INDEX IF NOT EXISTS ix_part_sourcing_part_number ON {src} (part_number)")
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_part_sourcing_record_number ON {src} (record_number)")
    )

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {alerts} (
                id SERIAL PRIMARY KEY,
                alert_number VARCHAR(32) NOT NULL UNIQUE,
                source VARCHAR(32) NOT NULL DEFAULT 'gidep',
                external_ref VARCHAR(128),
                title VARCHAR(512) NOT NULL,
                part_numbers TEXT,
                description TEXT,
                alert_date DATE,
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                impact_assessment TEXT,
                affects_inventory BOOLEAN NOT NULL DEFAULT FALSE,
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
        text(f"CREATE INDEX IF NOT EXISTS ix_counterfeit_alert_number ON {alerts} (alert_number)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('counterfeit_alerts', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('part_sourcing_records', schema)}"))
