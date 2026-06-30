"""Audit log User-Agent

Revision ID: 0038_audit_user_agent
Revises: 0037_access_token_denylist
Create Date: 2026-06-21

Adds ``audit_logs.user_agent`` so the immutable audit trail captures the client
User-Agent alongside the IP for forensic traceability. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0038_audit_user_agent"
down_revision: str | tuple[str, ...] | None = "0037_access_token_denylist"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("audit_logs", schema)
    bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS user_agent VARCHAR(256)"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    tbl = _qualify("audit_logs", schema)
    bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS user_agent"))
