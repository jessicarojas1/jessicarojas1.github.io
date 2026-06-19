"""User MFA (TOTP) fields

Revision ID: 0036_user_mfa
Revises: 0035_password_reset_tokens
Create Date: 2026-06-19

Adds ``users.mfa_secret`` and ``users.mfa_enabled`` for TOTP multi-factor auth.
Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0036_user_mfa"
down_revision: str | tuple[str, ...] | None = "0035_password_reset_tokens"
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
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS mfa_secret VARCHAR(64)"))
    bind.execute(
        text(
            f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("users", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS mfa_enabled"))
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS mfa_secret"))
