"""Outbound webhooks

Revision ID: 0033_webhooks
Revises: 0032_api_tokens
Create Date: 2026-06-18

Adds ``webhooks`` (registered endpoints + signing secret + event subscriptions)
and ``webhook_deliveries`` (attempt-tracked, retryable deliveries). Idempotent +
schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0033_webhooks"
down_revision: str | tuple[str, ...] | None = "0032_api_tokens"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    webhooks = _qualify("webhooks", schema)
    deliveries = _qualify("webhook_deliveries", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {webhooks} (
                id SERIAL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                url VARCHAR(1024) NOT NULL,
                secret VARCHAR(128) NOT NULL,
                event_types JSONB NOT NULL DEFAULT '[]'::jsonb,
                description VARCHAR(512),
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {deliveries} (
                id BIGSERIAL PRIMARY KEY,
                webhook_id INTEGER NOT NULL REFERENCES {webhooks} (id) ON DELETE CASCADE,
                event_type VARCHAR(128) NOT NULL,
                payload JSONB NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                last_status_code INTEGER,
                last_error TEXT,
                duration_ms INTEGER,
                next_attempt_at TIMESTAMPTZ,
                delivered_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_webhook_deliveries_webhook_id "
            f"ON {deliveries} (webhook_id)"
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_webhook_deliveries_status ON {deliveries} (status)")
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_webhook_deliveries_next_attempt_at "
            f"ON {deliveries} (next_attempt_at)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('webhook_deliveries', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('webhooks', schema)}"))
