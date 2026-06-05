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
    Base.metadata.create_all(bind=bind)


def downgrade() -> None:
    bind = op.get_bind()
    Base.metadata.drop_all(bind=bind)
