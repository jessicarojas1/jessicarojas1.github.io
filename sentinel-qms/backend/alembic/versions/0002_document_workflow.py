"""document control workflow — rebuild document tables for new schema

Revision ID: 0002_document_workflow
Revises: 0001_initial
Create Date: 2026-06-06

The document control module gained a redesigned approval workflow
(concept -> work_in_progress -> peer_review -> qa_review -> approved, plus an
obsolete terminal state), a new ``document_department`` enum, and a fixed-body
template (purpose / scope / definitions / responsibilities / detail /
revision_history / appendix) alongside version / approved_by / last_review_date.

Because the new ``DocumentStatus`` / ``DocumentType`` enum *values* differ from
the originals, the cleanest path is to drop the three document tables and their
Postgres enum types and recreate them from the current metadata. This is
acceptable here: the demo carries no real document data. All other tables are
untouched.

All DROPs are guarded (IF EXISTS / CASCADE) and schema-qualified when a
dedicated schema is configured, so this works whether or not a dedicated schema
is in use and is safe to re-run.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

from app.core.database import Base

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401

# revision identifiers, used by Alembic.
revision: str = "0002_document_workflow"
down_revision: str | None = "0001_initial"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


# The three document tables (drop in FK-dependency order: children first).
_DOC_TABLES = ("document_approvals", "document_revisions", "documents")
# Postgres enum types backing the document columns.
_DOC_ENUM_TYPES = ("document_status", "document_type", "document_department")


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    # Route this connection at the dedicated schema (mirrors env.py) so any
    # unqualified DDL resolves there too.
    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    is_postgres = bind.dialect.name == "postgresql"

    # 1) Drop the existing document tables (children first), guarded.
    for table in _DOC_TABLES:
        qualified = _qualify(table, schema)
        if is_postgres:
            bind.execute(text(f"DROP TABLE IF EXISTS {qualified} CASCADE"))
        else:
            bind.execute(text(f"DROP TABLE IF EXISTS {qualified}"))

    # 2) Drop the Postgres enum types so they can be recreated with new values.
    if is_postgres:
        for enum_name in _DOC_ENUM_TYPES:
            qualified = _qualify(enum_name, schema)
            bind.execute(text(f"DROP TYPE IF EXISTS {qualified} CASCADE"))

    # 3) Recreate ONLY the three document tables (and their enum types) from the
    #    current declarative metadata.
    tables = [Base.metadata.tables[_md_key(name, schema)] for name in _DOC_TABLES]
    # create_all builds dependencies in the correct (parent-first) order itself.
    Base.metadata.create_all(bind=bind, tables=tables, checkfirst=True)


def _md_key(table: str, schema: str | None) -> str:
    """MetaData keys are schema-qualified when a schema is bound."""
    return f"{schema}.{table}" if schema else table


def downgrade() -> None:
    # No-op: the original document schema is not reconstructed.
    pass
