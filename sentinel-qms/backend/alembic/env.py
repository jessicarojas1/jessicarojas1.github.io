"""Alembic migration environment for Sentinel QMS."""
from __future__ import annotations

from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool, text

from app.core.config import settings
from app.core.database import Base

# Import the models package so every table is registered on Base.metadata.
import app.models  # noqa: F401

config = context.config

# Inject the runtime DATABASE_URL (env-driven) over any value in alembic.ini.
config.set_main_option("sqlalchemy.url", settings.DATABASE_URL)

if config.config_file_name is not None:
    fileConfig(config.config_file_name)

target_metadata = Base.metadata

# Optional dedicated schema (for shared databases). Keep alembic_version inside
# it too so each app on a shared database tracks its own migration history.
DB_SCHEMA = settings.DB_SCHEMA.strip()


def run_migrations_offline() -> None:
    url = config.get_main_option("sqlalchemy.url")
    context.configure(
        url=url,
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        compare_type=True,
        compare_server_default=True,
        version_table_schema=DB_SCHEMA or None,
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        if DB_SCHEMA:
            # Create the schema and route this migration connection into it so
            # CREATE TABLE / alembic_version all land in the dedicated namespace.
            connection.execute(text(f'CREATE SCHEMA IF NOT EXISTS "{DB_SCHEMA}"'))
            connection.execute(text(f'SET search_path TO "{DB_SCHEMA}", public'))
            connection.commit()
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            compare_type=True,
            compare_server_default=True,
            version_table_schema=DB_SCHEMA or None,
        )
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
