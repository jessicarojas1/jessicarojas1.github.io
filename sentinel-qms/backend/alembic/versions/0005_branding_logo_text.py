"""branding — widen org_settings.logo_url to TEXT for data: URLs

Revision ID: 0005_branding_logo_text
Revises: 0004_org_settings
Create Date: 2026-06-06

Branding now accepts an uploaded logo stored inline as a ``data:image/...`` URL,
which easily exceeds the original ``VARCHAR(1024)``. This migration widens
``org_settings.logo_url`` to an unbounded ``TEXT`` column. The connection is
routed at the dedicated schema when one is configured — mirrors 0004.
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy import text

from app.core.database import Base

# revision identifiers, used by Alembic.
revision: str = "0005_branding_logo_text"
down_revision: str | None = "0004_org_settings"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    # SQLite cannot ALTER a column type in place; it treats VARCHAR/TEXT the same
    # (dynamic typing) so the change is a no-op there. Only Postgres needs the DDL.
    if bind.dialect.name == "postgresql":
        # search_path is already routed above, so an unqualified name resolves
        # to the configured schema (mirrors 0003/0004).
        op.alter_column(
            "org_settings",
            "logo_url",
            existing_type=sa.String(length=1024),
            type_=sa.Text(),
            existing_nullable=True,
        )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    if bind.dialect.name == "postgresql":
        op.alter_column(
            "org_settings",
            "logo_url",
            existing_type=sa.Text(),
            type_=sa.String(length=1024),
            existing_nullable=True,
        )
