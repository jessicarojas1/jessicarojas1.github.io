#!/bin/sh
# Sentinel QMS API container entrypoint.
# Applies database migrations and seeds reference data before starting the
# server. Both steps are idempotent and individually gated so production
# deployments that run migrations via a separate Job can disable them
# (AUTO_MIGRATE=0 / AUTO_SEED=0).
set -e

if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
  echo "[entrypoint] Applying database migrations (alembic upgrade head)..."
  if ! alembic upgrade head > /tmp/migrate.log 2>&1; then
    echo "[entrypoint] ================= MIGRATION FAILED ================="
    echo "[entrypoint] Reason (filtered):"
    grep -iE "does not exist|already exists|permission denied|could not|undefinedtable|duplicateobject|psycopg\.|sqlalchemy\.exc\.[A-Za-z]+Error" /tmp/migrate.log | head -20 || true
    echo "[entrypoint] ---------------- last 30 log lines -----------------"
    tail -n 30 /tmp/migrate.log
    echo "[entrypoint] ===================================================="
    exit 1
  fi
  echo "[entrypoint] Migrations applied successfully."
fi

if [ "${AUTO_SEED:-1}" = "1" ]; then
  echo "[entrypoint] Seeding roles and reference data..."
  # Seeding is best-effort; a failure here must not block the API from starting.
  python -m app.seed || echo "[entrypoint] seed step skipped or failed (non-fatal)"
fi

echo "[entrypoint] Starting: $*"
exec "$@"
