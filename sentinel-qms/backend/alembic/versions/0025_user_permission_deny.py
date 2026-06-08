"""User permission deny flag

Revision ID: 0025_user_permission_deny
Revises: 0024_kc_owner
Create Date: 2026-06-08

Adds a ``deny`` flag to ``user_permission_grants`` so an admin can unassign a
permission a user's role would otherwise grant. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0025_user_permission_deny"
down_revision: str | tuple[str, ...] | None = "0024_kc_owner"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("user_permission_grants", schema)
    bind.execute(
        text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS deny BOOLEAN NOT NULL DEFAULT FALSE")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("user_permission_grants", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS deny"))
