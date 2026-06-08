"""Executive KPI targets + Cost-of-Quality unit costs

Revision ID: 0009_exec_kpi_targets_coq_costs
Revises: 0008_sla_and_report_schedule
Create Date: 2026-06-07

Adds configurable executive-dashboard settings to the ``org_settings``
singleton, all idempotent and schema-aware:

* KPI RAG targets (``kpi_target_*``) — the thresholds the executive dashboard
  evaluates each KPI against (previously hardcoded).
* Cost-of-Quality per-event unit costs (``coq_cost_*``) — used to convert the
  event-based COQ counts into dollar figures.
"""

from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

# revision identifiers, used by Alembic.
revision: str = "0009_exec_kpi_targets_coq_costs"
down_revision: str | tuple[str, ...] | None = "0008_sla_and_report_schedule"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


# (column name, "ADD COLUMN" body) pairs for org_settings.
_COLUMNS: list[tuple[str, str]] = [
    ("kpi_target_open_ncrs", "kpi_target_open_ncrs NUMERIC(10, 2) NOT NULL DEFAULT 10"),
    ("kpi_target_overdue_capas", "kpi_target_overdue_capas NUMERIC(10, 2) NOT NULL DEFAULT 0"),
    ("kpi_target_open_findings", "kpi_target_open_findings NUMERIC(10, 2) NOT NULL DEFAULT 5"),
    ("kpi_target_escapes", "kpi_target_escapes NUMERIC(10, 2) NOT NULL DEFAULT 3"),
    ("kpi_target_capa_on_time", "kpi_target_capa_on_time NUMERIC(10, 2) NOT NULL DEFAULT 90"),
    (
        "kpi_target_supplier_quality",
        "kpi_target_supplier_quality NUMERIC(10, 2) NOT NULL DEFAULT 95",
    ),
    ("kpi_target_supplier_otd", "kpi_target_supplier_otd NUMERIC(10, 2) NOT NULL DEFAULT 95"),
    ("coq_cost_ncr", "coq_cost_ncr NUMERIC(12, 2) NOT NULL DEFAULT 500"),
    ("coq_cost_complaint", "coq_cost_complaint NUMERIC(12, 2) NOT NULL DEFAULT 2000"),
    ("coq_cost_inspection", "coq_cost_inspection NUMERIC(12, 2) NOT NULL DEFAULT 75"),
    ("coq_cost_audit", "coq_cost_audit NUMERIC(12, 2) NOT NULL DEFAULT 1500"),
    ("coq_cost_capa", "coq_cost_capa NUMERIC(12, 2) NOT NULL DEFAULT 1200"),
]


def upgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{schema}"'))
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    settings_tbl = _qualify("org_settings", schema)
    for _name, body in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {settings_tbl} ADD COLUMN IF NOT EXISTS {body}"))


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    settings_tbl = _qualify("org_settings", schema)
    for name, _body in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {settings_tbl} DROP COLUMN IF EXISTS {name}"))
