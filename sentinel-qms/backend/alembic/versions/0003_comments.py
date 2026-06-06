"""comments / collaboration — create the comments table

Revision ID: 0003_comments
Revises: 0002_document_workflow
Create Date: 2026-06-06

Adds the ``comments`` table backing threaded notes / @mentions on every record.
Only this single table is created (from the current declarative metadata), and
the connection is routed at the dedicated schema when one is configured — mirrors
0002_document_workflow.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

from app.core.database import Base

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.models.comment import Comment

# revision identifiers, used by Alembic.
revision: str = "0003_comments"
down_revision: str | None = "0002_document_workflow"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    # Route this connection at the dedicated schema (mirrors env.py / 0002) so any
    # unqualified DDL resolves there too.
    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    # Create ONLY the comments table from the current declarative metadata.
    Base.metadata.create_all(bind=bind, tables=[Comment.__table__], checkfirst=True)


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    is_postgres = bind.dialect.name == "postgresql"
    qualified = _qualify("comments", schema)
    if is_postgres:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified} CASCADE"))
    else:
        bind.execute(text(f"DROP TABLE IF EXISTS {qualified}"))
