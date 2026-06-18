"""User notification preferences

Revision ID: 0031_user_notification_prefs
Revises: 0030_fmea
Create Date: 2026-06-18

Adds ``users.notification_prefs`` (JSON) for per-user notification opt-outs.
Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0031_user_notification_prefs"
down_revision: str | tuple[str, ...] | None = "0030_fmea"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("users", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS notification_prefs JSONB"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("users", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS notification_prefs"))
