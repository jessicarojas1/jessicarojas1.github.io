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
# All objects live in the `aegis` schema (schema.sql uses unqualified names and
# relies on the search_path; install.php does the same). Create it and pin the
# search_path for every psql session so tables land in `aegis`, not `public`.
export PGOPTIONS="--search_path=aegis,public"
PSQL="psql -v ON_ERROR_STOP=1 --no-psqlrc --username ${POSTGRES_USER} --dbname ${POSTGRES_DB}"

echo "[initdb] Creating aegis schema..."
$PSQL -c "CREATE SCHEMA IF NOT EXISTS aegis;"

echo "[initdb] Applying base schema..."
$PSQL -f "${DB_DIR}/schema.sql"

echo "[initdb] Applying migrations in order..."
for f in $(ls "${DB_DIR}/migrations"/*.sql 2>/dev/null | sort); do
  echo "[initdb]   -> $(basename "$f")"
  $PSQL -f "$f"
done

echo "[initdb] Database initialized."
