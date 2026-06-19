#!/bin/sh
# Postgres first-boot initializer (runs once, from docker-entrypoint-initdb.d).
#
# Applies the base schema and then EVERY migration in numeric order, so a fresh
# `docker compose up` produces a fully-migrated database. This replaces the old
# approach of mounting a hand-picked subset of migration files (which silently
# left a fresh DB on an outdated schema — only 001–003 of 024 were applied).
#
# Migrations are idempotent (IF NOT EXISTS / ON CONFLICT), so re-application is
# safe; ON_ERROR_STOP makes a genuine failure fail the container build loudly.
set -eu

DB_DIR="${AEGIS_DB_DIR:-/aegis-db}"
PSQL="psql -v ON_ERROR_STOP=1 --no-psqlrc --username ${POSTGRES_USER} --dbname ${POSTGRES_DB}"

echo "[initdb] Applying base schema..."
$PSQL -f "${DB_DIR}/schema.sql"

echo "[initdb] Applying migrations in order..."
for f in $(ls "${DB_DIR}/migrations"/*.sql 2>/dev/null | sort); do
  echo "[initdb]   -> $(basename "$f")"
  $PSQL -f "$f"
done

echo "[initdb] Database initialized."
