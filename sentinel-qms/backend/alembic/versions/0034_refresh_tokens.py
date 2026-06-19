"""Refresh token rotation + revocation

Revision ID: 0034_refresh_tokens
Revises: 0033_webhooks
Create Date: 2026-06-19

Adds ``refresh_tokens`` (server-side records keyed by the token ``jti``) backing
refresh-token rotation, reuse detection, and revocation. Idempotent +
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0034_refresh_tokens"
down_revision: str | tuple[str, ...] | None = "0033_webhooks"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tokens = _qualify("refresh_tokens", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tokens} (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                jti VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                revoked_at TIMESTAMPTZ,
                replaced_by_jti VARCHAR(64),
                user_agent VARCHAR(256),
                ip_address VARCHAR(64),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_refresh_tokens_user_id ON {tokens} (user_id)")
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_refresh_tokens_jti ON {tokens} (jti)"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('refresh_tokens', schema)}"))
