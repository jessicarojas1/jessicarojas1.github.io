"""Customer & contract register with requirement flow-down

Revision ID: 0017_customer_contracts
Revises: 0016_scar_capa_link
Create Date: 2026-06-08

Adds ``customers``, ``contracts`` and ``contract_requirements`` (idempotent,
schema-aware).
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

import app.models  # noqa: F401
from app.core.database import Base

revision: str = "0017_customer_contracts"
down_revision: str | tuple[str, ...] | None = "0016_scar_capa_link"
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

    cust = _qualify("customers", schema)
    contr = _qualify("contracts", schema)
    req = _qualify("contract_requirements", schema)

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {cust} (
                id SERIAL PRIMARY KEY,
                code VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                cage_code VARCHAR(16),
                country VARCHAR(64),
                contact_name VARCHAR(255),
                contact_email VARCHAR(255),
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                notes TEXT,
                is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                deleted_at TIMESTAMPTZ,
                deleted_by INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_customers_code ON {cust} (code)"))

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {contr} (
                id SERIAL PRIMARY KEY,
                contract_number VARCHAR(64) NOT NULL UNIQUE,
                customer_id INTEGER NOT NULL REFERENCES {cust} (id) ON DELETE CASCADE,
                title VARCHAR(512) NOT NULL,
                dpas_rating VARCHAR(16),
                itar_controlled BOOLEAN NOT NULL DEFAULT FALSE,
                dfars_clauses TEXT,
                value NUMERIC(16, 2),
                start_date DATE,
                end_date DATE,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                notes TEXT,
                is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
                deleted_at TIMESTAMPTZ,
                deleted_by INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_contracts_number ON {contr} (contract_number)")
    )
    bind.execute(text(f"CREATE INDEX IF NOT EXISTS ix_contracts_customer ON {contr} (customer_id)"))

    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {req} (
                id SERIAL PRIMARY KEY,
                contract_id INTEGER NOT NULL REFERENCES {contr} (id) ON DELETE CASCADE,
                clause VARCHAR(64),
                description TEXT NOT NULL,
                flow_down_to VARCHAR(32) NOT NULL DEFAULT 'internal',
                status VARCHAR(32) NOT NULL DEFAULT 'open',
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_by INTEGER,
                updated_by INTEGER
            )
            """
        )
    )
    bind.execute(
        text(f"CREATE INDEX IF NOT EXISTS ix_contract_requirements_contract ON {req} (contract_id)")
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema
    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('contract_requirements', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('contracts', schema)}"))
    bind.execute(text(f"DROP TABLE IF EXISTS {_qualify('customers', schema)}"))
