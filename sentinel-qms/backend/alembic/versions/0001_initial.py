"""initial schema — all Sentinel QMS tables

Revision ID: 0001_initial
Revises:
Create Date: 2026-06-05

This initial migration creates the full schema from the declarative models'
metadata so it stays exactly in sync with the ORM definitions (FKs, indexes,
unique constraints, and enum types included). Subsequent migrations should be
authored as explicit, incremental diffs.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op

from app.core.database import Base

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401

# revision identifiers, used by Alembic.
revision: str = "0001_initial"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    bind = op.get_bind()
    # Self-heal any partial objects left by a prior failed run. This is safe
    # because the MetaData is bound to a dedicated schema, so drop_all only ever
    # targets Sentinel's own tables — never anything in ``public`` on a shared
    # database. Skipped if no dedicated schema is configured (e.g. plain public).
    if Base.metadata.schema and Base.metadata.schema != "public":
        Base.metadata.drop_all(bind=bind, checkfirst=True)
    Base.metadata.create_all(bind=bind)


def downgrade() -> None:
    bind = op.get_bind()
    Base.metadata.drop_all(bind=bind)
