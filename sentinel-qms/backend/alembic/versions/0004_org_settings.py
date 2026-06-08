"""org settings & branding — create the org_settings table

Revision ID: 0004_org_settings
Revises: 0003_comments
Create Date: 2026-06-06

Adds the ``org_settings`` singleton table backing organization-wide settings
and branding. Only this single table is created (from the current declarative
metadata), and the connection is routed at the dedicated schema when one is
configured — mirrors 0003_comments.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base
from app.models.settings import OrgSettings

# revision identifiers, used by Alembic.
revision: str = "0004_org_settings"
down_revision: str | None = "0003_comments"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    # Route this connection at the dedicated schema (mirrors env.py / 0003) so any
    # unqualified DDL resolves there too.
    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    # Create ONLY the org_settings table from the current declarative metadata.
    Base.metadata.create_all(bind=bind, tables=[OrgSettings.__table__], checkfirst=True)


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    is_postgres = bind.dialect.name == "postgresql"
    qualified = _qualify("org_settings", schema)
    if is_postgres:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified} CASCADE"))
    else:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified}"))
