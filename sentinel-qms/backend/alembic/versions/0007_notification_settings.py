"""multi-channel notification settings — add columns to org_settings

Revision ID: 0007_notification_settings
Revises: 0006_user_permission_grants
Create Date: 2026-06-06

Adds three admin-configurable notification-delivery columns to the existing
``org_settings`` singleton table:

* ``notifications_email_enabled`` — master toggle for SMTP email delivery
* ``teams_webhook_url`` — Microsoft Teams incoming webhook override
* ``slack_webhook_url`` — Slack incoming webhook override

Unlike the table-creating migrations that precede it, this one ALTERs an
existing table, so it issues schema-aware, idempotent ``ALTER TABLE ... ADD
COLUMN IF NOT EXISTS`` statements (safe to re-run) rather than ``create_all``.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

from app.core.database import Base

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401

# revision identifiers, used by Alembic.
revision: str = "0007_notification_settings"
down_revision: str | None = "0006_user_permission_grants"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


# (column name, "ADD COLUMN" body) pairs.
_COLUMNS: list[tuple[str, str]] = [
    (
        "notifications_email_enabled",
        "notifications_email_enabled BOOLEAN NOT NULL DEFAULT FALSE",
    ),
    ("teams_webhook_url", "teams_webhook_url VARCHAR(1024)"),
    ("slack_webhook_url", "slack_webhook_url VARCHAR(1024)"),
]


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    table = _qualify("org_settings", schema)
    for _name, body in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {table} ADD COLUMN IF NOT EXISTS {body}"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    table = _qualify("org_settings", schema)
    for name, _body in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {table} DROP COLUMN IF EXISTS {name}"))
