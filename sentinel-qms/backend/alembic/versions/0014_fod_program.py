"""FOD (Foreign Object Debris) program (AS9146)

Revision ID: 0014_fod_program
Revises: 0013_counterfeit_ncr_link
Create Date: 2026-06-08

Adds the ``fod_zones`` and ``fod_events`` tables (idempotent, schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0014_fod_program"
down_revision: str | tuple[str, ...] | None = "0013_counterfeit_ncr_link"
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

    zones = _qualify("fod_zones", schema)
    events = _qualify("fod_events", schema)
    ncr = _qualify("nonconformances", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {zones} (
                id SERIAL PRIMARY KEY,
                code VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                risk_level VARCHAR(32) NOT NULL DEFAULT 'medium',
                description TEXT,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
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
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_fod_zones_code ON {zones} (code)"))

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {events} (
                id SERIAL PRIMARY KEY,
                event_number VARCHAR(32) NOT NULL UNIQUE,
                zone_id INTEGER REFERENCES {zones} (id),
                title VARCHAR(512) NOT NULL,
                description TEXT,
                object_type VARCHAR(255),
                location VARCHAR(255),
                severity VARCHAR(32) NOT NULL DEFAULT 'medium',
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                discovered_date DATE,
                root_cause TEXT,
                corrective_action TEXT,
                ncr_id INTEGER REFERENCES {ncr} (id),
                closed_at TIMESTAMPTZ,
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
        text(f"CREATE INDEX IF NOT EXISTS ix_fod_events_event_number ON {events} (event_number)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('fod_events', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('fod_zones', schema)}"))
