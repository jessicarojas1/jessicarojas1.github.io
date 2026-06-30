"""Lessons Learned registry

Revision ID: 0040_lessons_learned
Revises: 0039_document_acknowledgements
Create Date: 2026-06-21

Adds the ``lessons_learned`` table backing the Lessons Learned module
(organizational learning / knowledge retention). Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0040_lessons_learned"
down_revision: str | tuple[str, ...] | None = "0039_document_acknowledgements"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    lessons = _qualify("lessons_learned", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {lessons} (
                id SERIAL PRIMARY KEY,
                lesson_number VARCHAR(32) NOT NULL UNIQUE,
                title VARCHAR(512) NOT NULL,
                category VARCHAR(16) NOT NULL DEFAULT 'process',
                source VARCHAR(16) NOT NULL DEFAULT 'other',
                source_ref VARCHAR(64),
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                department VARCHAR(128),
                owner_id INTEGER REFERENCES {users} (id),
                event_date DATE,
                what_happened TEXT,
                root_cause TEXT,
                recommendation TEXT,
                published_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER,
                is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                deleted_at TIMESTAMPTZ,
                deleted_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_lessons_learned_lesson_number "
            f"ON {lessons} (lesson_number)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('lessons_learned', schema)}"))
