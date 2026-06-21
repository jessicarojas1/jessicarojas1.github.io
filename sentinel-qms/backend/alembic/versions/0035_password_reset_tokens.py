"""Password reset tokens

Revision ID: 0035_password_reset_tokens
Revises: 0034_refresh_tokens
Create Date: 2026-06-19

Adds ``password_reset_tokens`` (single-use, expiring, hash-only) backing
self-service password reset. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0035_password_reset_tokens"
down_revision: str | tuple[str, ...] | None = "0034_refresh_tokens"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tokens = _qualify("password_reset_tokens", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tokens} (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                used_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_password_reset_tokens_user_id ON {tokens} (user_id)")
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_password_reset_tokens_token_hash "
            f"ON {tokens} (token_hash)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('password_reset_tokens', schema)}"))
