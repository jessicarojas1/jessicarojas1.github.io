"""Access-token denylist for true logout revocation

Revision ID: 0037_access_token_denylist
Revises: 0036_user_mfa
Create Date: 2026-06-21

Adds ``access_token_denylist`` (rows keyed by an access token's ``jti`` with its
expiry) so that logging out can immediately revoke an already-issued short-lived
access token. Used as the DB-backed fallback when no shared cache (Redis) is
configured. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0037_access_token_denylist"
down_revision: str | tuple[str, ...] | None = "0036_user_mfa"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    table = _qualify("access_token_denylist", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {table} (
                id BIGSERIAL PRIMARY KEY,
                jti VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_access_token_denylist_jti ON {table} (jti)")
    )
    bind.execute(
        text(
            f"CREATE INDEX IF NOT EXISTS ix_access_token_denylist_expires_at "
            f"ON {table} (expires_at)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('access_token_denylist', schema)}"))
