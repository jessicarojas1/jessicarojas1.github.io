"""Document workflow assignees (author / reviewer / approver)

Revision ID: 0045_document_assignees
Revises: 0044_retention_policies
Create Date: 2026-07-02

Adds three nullable user references to ``documents`` — ``author_id``,
``reviewer_id`` and ``approver_id`` — the workflow assignees. They are optional
while the document is a Concept and are required before it can advance from
Concept to Work In Progress (enforced in the transition endpoint, not by a NOT
NULL constraint, so existing Concept documents remain valid).

``approver_id`` is the person expected to approve the document and is distinct
from ``approved_by``, which records who actually approved it.

Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

# revision identifiers, used by Alembic.
revision: str = "0045_document_assignees"
down_revision: str | tuple[str, ...] | None = "0044_retention_policies"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


# New nullable user-reference columns on ``documents``.
_COLUMNS: tuple[str, ...] = ("author_id", "reviewer_id", "approver_id")


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    documents = _qualify("documents", schema)
    users = _qualify("users", schema)
    for col in _COLUMNS:
        bind.execute(
            text(
                f"ALTER TABLE {documents} "
                f"ADD COLUMN IF NOT EXISTS {col} INTEGER REFERENCES {users} (id)"
            )
        )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    documents = _qualify("documents", schema)
    for col in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {documents} DROP COLUMN IF EXISTS {col}"))
