"""user-level page permission overrides — create user_page_permissions

Revision ID: 0005_user_page_permissions
Revises: 0004_org_settings
Create Date: 2026-06-06

Adds the ``user_page_permissions`` table backing per-user permission overrides
that layer on top of role permissions. Only this single table is created from
the current declarative metadata, routed at the dedicated schema when one is
configured — mirrors 0004_org_settings.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

from app.core.database import Base

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.models.permission import UserPagePermission

# revision identifiers, used by Alembic.
revision: str = "0005_user_page_permissions"
down_revision: str | None = "0004_org_settings"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    Base.metadata.create_all(
        bind=bind, tables=[UserPagePermission.__table__], checkfirst=True
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    qualified = _qualify("user_page_permissions", schema)
    if bind.dialect.name == "postgresql":
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified} CASCADE"))
    else:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified}"))
