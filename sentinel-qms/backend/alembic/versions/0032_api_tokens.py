"""Personal Access Tokens (scoped API keys)

Revision ID: 0032_api_tokens
Revises: 0031_user_notification_prefs
Create Date: 2026-06-18

Adds the ``api_tokens`` table backing scoped, hashed Personal Access Tokens for
programmatic / service access. Only the SHA-256 hash of each secret is stored.
Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0032_api_tokens"
down_revision: str | tuple[str, ...] | None = "0031_user_notification_prefs"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tokens = _qualify("api_tokens", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {tokens} (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                name VARCHAR(128) NOT NULL,
                token_prefix VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
                last_used_at TIMESTAMPTZ,
                expires_at TIMESTAMPTZ,
                revoked_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_api_tokens_user_id ON {tokens} (user_id)"))
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_api_tokens_token_hash ON {tokens} (token_hash)")
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_api_tokens_token_prefix ON {tokens} (token_prefix)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tokens = _qualify("api_tokens", schema)
    bind.execute(text(f"DROP TABLE IF EXISTS {tokens}"))
