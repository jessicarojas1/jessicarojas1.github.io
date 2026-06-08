"""granular per-user permission grants — create user_permission_grants

Revision ID: 0006_user_permission_grants
Revises: 0005_user_page_permissions
Create Date: 2026-06-06

Adds the ``user_permission_grants`` table backing the additive granular
(module x action) IAM layer (see :mod:`app.core.iam`). Only this single table is
created from the current declarative metadata, routed at the dedicated schema
when one is configured — mirrors 0005_user_page_permissions.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base
from app.models.iam import UserPermissionGrant

# revision identifiers, used by Alembic.
revision: str = "0006_user_permission_grants"
down_revision: str | None = "0005_user_page_permissions"
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

    Base.metadata.create_all(bind=bind, tables=[UserPermissionGrant.__table__], checkfirst=True)


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    qualified = _qualify("user_permission_grants", schema)
    if bind.dialect.name == "postgresql":
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified} CASCADE"))
    else:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified}"))
