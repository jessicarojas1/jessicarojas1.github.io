"""Standards authorship columns

Revision ID: 0026_standards_authorship
Revises: 0025_user_permission_deny
Create Date: 2026-06-09

The ``Standard`` and ``StandardRequirement`` models use ``TimestampMixin``,
which declares ``created_by`` / ``updated_by`` — but migration 0010 created the
``standards`` / ``standard_requirements`` tables with only ``created_at`` /
``updated_at``. Every ORM SELECT/INSERT names the missing columns, so seeding
and the Standards & Coverage page fail with ``UndefinedColumn``. This backfills
the columns idempotently (no-op where they already exist). Schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0026_standards_authorship"
down_revision: str | tuple[str, ...] | None = "0025_user_permission_deny"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

_TABLES = ("standards", "standard_requirements")
_COLUMNS = ("created_by", "updated_by")


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    for table in _TABLES:
        tbl = _qualify(table, schema)
        for col in _COLUMNS:
            bind.execute(text(f"ALTER TABLE {tbl} ADD COLUMN IF NOT EXISTS {col} INTEGER"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    for table in _TABLES:
        tbl = _qualify(table, schema)
        for col in _COLUMNS:
            bind.execute(text(f"ALTER TABLE {tbl} DROP COLUMN IF EXISTS {col}"))
