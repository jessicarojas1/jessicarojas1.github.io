"""SLA escalation + scheduled report digest

Revision ID: 0008_sla_and_report_schedule
Revises: 0007_notification_settings, 0005_branding_logo_text
Create Date: 2026-06-07

This revision also **merges** the two previously-divergent heads
(``0007_notification_settings`` and the dangling ``0005_branding_logo_text``,
which both trace back to ``0004_org_settings``) into a single head so that
``alembic upgrade head`` resolves unambiguously on a fresh database.

Two related additions, both idempotent and schema-aware (so they are safe to
re-run and respect the dedicated ``DB_SCHEMA``):

1. New SLA + report-digest columns on the ``org_settings`` singleton:
   * ``sla_enabled`` and per-record-type SLA windows
     (``sla_capa_due_soon_days``, ``sla_ncr_minor_days``,
     ``sla_ncr_major_days``, ``sla_ncr_critical_days``)
   * scheduled report digest config (``report_schedule_enabled``,
     ``report_schedule_frequency``, ``report_schedule_recipients``,
     ``report_schedule_last_sent_at``)

2. New ``sla_escalations`` ledger table (one row per fired escalation) with a
   unique constraint on ``(entity_type, entity_id, level)`` so each record is
   escalated at most once per level and the sweep stays race-safe.
"""
from __future__ import annotations

from collections.abc import Sequence

from alembic import op
from sqlalchemy import text

# Ensure every model is imported so Base.metadata is fully populated.
import app.models  # noqa: F401
from app.core.database import Base

# revision identifiers, used by Alembic.
revision: str = "0008_sla_and_report_schedule"
# Tuple down_revision => this is also a merge point unifying the two prior heads.
down_revision: str | tuple[str, ...] | None = (
    "0007_notification_settings",
    "0005_branding_logo_text",
)
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def _qualify(name: str, schema: str | None) -> str:
    return f'"{schema}"."{name}"' if schema else f'"{name}"'


# (column name, "ADD COLUMN" body) pairs for org_settings.
_COLUMNS: list[tuple[str, str]] = [
    ("sla_enabled", "sla_enabled BOOLEAN NOT NULL DEFAULT TRUE"),
    ("sla_capa_due_soon_days", "sla_capa_due_soon_days INTEGER NOT NULL DEFAULT 7"),
    ("sla_ncr_minor_days", "sla_ncr_minor_days INTEGER NOT NULL DEFAULT 30"),
    ("sla_ncr_major_days", "sla_ncr_major_days INTEGER NOT NULL DEFAULT 14"),
    ("sla_ncr_critical_days", "sla_ncr_critical_days INTEGER NOT NULL DEFAULT 7"),
    (
        "report_schedule_enabled",
        "report_schedule_enabled BOOLEAN NOT NULL DEFAULT FALSE",
    ),
    (
        "report_schedule_frequency",
        "report_schedule_frequency VARCHAR(16) NOT NULL DEFAULT 'weekly'",
    ),
    ("report_schedule_recipients", "report_schedule_recipients TEXT"),
    (
        "report_schedule_last_sent_at",
        "report_schedule_last_sent_at TIMESTAMPTZ",
    ),
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

    esc_tbl = _qualify("sla_escalations", schema)
    bind.execute(
        text(
            f"""
            CREATE TABLE IF NOT EXISTS {esc_tbl} (
                id SERIAL PRIMARY KEY,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                level VARCHAR(32) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            """
        )
    )
    bind.execute(
        text(
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_sla_escalation_entity_level "
            f"ON {esc_tbl} (entity_type, entity_id, level)"
        )
    )


def downgrade() -> None:
    bind = op.get_bind()
    schema = Base.metadata.schema

    if schema and schema != "public":
        bind.execute(text(f'SET search_path TO "{schema}", public'))

    esc_tbl = _qualify("sla_escalations", schema)
    bind.execute(text(f"DROP TABLE IF EXISTS {esc_tbl}"))

    settings_tbl = _qualify("org_settings", schema)
    for name, _body in _COLUMNS:
        bind.execute(text(f"ALTER TABLE {settings_tbl} DROP COLUMN IF EXISTS {name}"))
