#!/usr/bin/env bash
#
# Sentinel QMS — database bootstrap.
#
# Runs EVERY query required to stand up (or upgrade) the database for a cloud
# deployment: applies the full schema via Alembic migrations, then seeds the
# RBAC reference data the application needs. Idempotent — safe to run on every
# release.
#
# Designed to run INSIDE the backend image (it needs Alembic + the `app`
# package). Typical usage:
#
#   AWS GovCloud (ECS one-off task):
#     aws ecs run-task --cluster <c> --task-definition sentinel-qms-api \
#       --overrides '{"containerOverrides":[{"name":"api",
#         "command":["/app/scripts/db-bootstrap.sh"]}]}'
#
#   Azure Government (Container Apps job):
#     az containerapp job create ... --image <acr>/sentinel-qms-api:<tag> \
#       --command "/app/scripts/db-bootstrap.sh"
#
#   Locally / any container:
#     DATABASE_URL=postgresql+psycopg://... ./scripts/db-bootstrap.sh
#
# Environment:
#   DATABASE_URL     (required) target Postgres connection string. A bare
#                    postgres:// scheme is normalized automatically.
#   BOOTSTRAP_ADMIN  (default 0) when "1", also create the first admin from
#                    ADMIN_EMAIL / ADMIN_PASSWORD (use once per environment).
#   ADMIN_EMAIL      first administrator's email (when BOOTSTRAP_ADMIN=1).
#   ADMIN_PASSWORD   first administrator's password (when BOOTSTRAP_ADMIN=1).
#
set -euo pipefail

: "${DATABASE_URL:?DATABASE_URL must be set to the target Postgres connection string}"

# Run from the app root so alembic.ini and the app package resolve.
cd "$(dirname "$0")/.." 2>/dev/null || cd /app

echo "==> [1/2] Applying database schema (alembic upgrade head)"
alembic upgrade head

if [ "${BOOTSTRAP_ADMIN:-0}" = "1" ]; then
  echo "==> [2/2] Seeding reference data (RBAC roles + bootstrap admin)"
else
  echo "==> [2/2] Seeding reference data (RBAC roles)"
fi
python -m app.dbinit

echo "==> Database bootstrap complete."
