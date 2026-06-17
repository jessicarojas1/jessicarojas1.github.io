"""FMEA (PFMEA/DFMEA) worksheets

Revision ID: 0030_fmea
Revises: 0029_customer_surveys
Create Date: 2026-06-17

Adds the ``fmeas`` and ``fmea_items`` tables backing the FMEA module
(AS9145 / AIAG-VDA, severity x occurrence x detection = RPN). Idempotent +
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0030_fmea"
down_revision: str | tuple[str, ...] | None = "0029_customer_surveys"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    fmeas = _qualify("fmeas", schema)
    items = _qualify("fmea_items", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {fmeas} (
                id SERIAL PRIMARY KEY,
                fmea_number VARCHAR(32) NOT NULL UNIQUE,
                title VARCHAR(512) NOT NULL,
                fmea_type VARCHAR(16) NOT NULL DEFAULT 'process',
                part_number VARCHAR(128),
                process_ref VARCHAR(255),
                scope TEXT,
                owner_id INTEGER REFERENCES {users} (id),
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
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
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_fmeas_number ON {fmeas} (fmea_number)"))
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {items} (
                id SERIAL PRIMARY KEY,
                fmea_id INTEGER NOT NULL REFERENCES {fmeas} (id) ON DELETE CASCADE,
                function VARCHAR(512) NOT NULL,
                failure_mode VARCHAR(512) NOT NULL,
                effect TEXT,
                cause TEXT,
                controls TEXT,
                severity INTEGER NOT NULL DEFAULT 1,
                occurrence INTEGER NOT NULL DEFAULT 1,
                detection INTEGER NOT NULL DEFAULT 1,
                recommended_action TEXT,
                action_owner_id INTEGER REFERENCES {users} (id),
                target_date DATE,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_fmea_items_fmea_id ON {items} (fmea_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('fmea_items', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('fmeas', schema)}"))
