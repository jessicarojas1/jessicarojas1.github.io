"""Document read-and-acknowledge

Revision ID: 0039_document_acknowledgements
Revises: 0038_audit_user_agent
Create Date: 2026-06-21

Adds ``documents.acknowledgement_required`` and the ``document_acknowledgements``
table (one attestation per document/revision/user) for controlled-document
read-and-acknowledge workflows. Idempotent + schema-aware.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0039_document_acknowledgements"
down_revision: str | tuple[str, ...] | None = "0038_audit_user_agent"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    documents = _qualify("documents", schema)
    acks = _qualify("document_acknowledgements", schema)
    users = _qualify("users", schema)

    bind.execute(
        text(
            f"ALTER TABLE {documents} ADD COLUMN IF NOT EXISTS "
            "acknowledgement_required BOOLEAN NOT NULL DEFAULT FALSE"
        )
    )
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {acks} (
                id SERIAL PRIMARY KEY,
                document_id INTEGER NOT NULL REFERENCES {documents} (id) ON DELETE CASCADE,
                revision VARCHAR(16) NOT NULL DEFAULT '',
                user_id INTEGER NOT NULL REFERENCES {users} (id) ON DELETE CASCADE,
                user_name VARCHAR(255) NOT NULL,
                note TEXT,
                acknowledged_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_doc_ack_doc_rev_user UNIQUE (document_id, revision, user_id)
            )
            """
        )
    )
    bind.execute(
        text(
            "CREATE INDEX IF NOT EXISTS ix_document_acknowledgements_document_id "
            f"ON {acks} (document_id)"
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_document_acknowledgements_user_id ON {acks} (user_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('document_acknowledgements', schema)}"))
    bind.execute(
        text(
            f"ALTER TABLE {_qualify('documents', schema)} "
            "DROP COLUMN IF EXISTS acknowledgement_required"
        )
    )
