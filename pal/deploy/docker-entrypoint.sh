#!/bin/sh
# PAL container entrypoint: validates required configuration, then runs one role.
set -eu

cd /srv/pal/backend

require() {
    for name in "$@"; do
        eval "value=\${$name:-}"
        if [ -z "$value" ]; then
            echo "pal: required environment variable $name is not set" >&2
            exit 64
        fi
    done
}

role="${1:-web}"
[ "$#" -gt 0 ] && shift

case "$role" in
    web)
        require APP_SECRET DATABASE_URL PAL_BASE_URL TRUSTED_HOSTS
        exec supervisord -c /etc/supervisord.conf
        ;;
    worker)
        require APP_SECRET DATABASE_URL
        # Exit periodically so memory is reclaimed; the orchestrator restarts the container.
        exec php bin/console messenger:consume async scheduler_default \
            --time-limit="${PAL_WORKER_TIME_LIMIT:-3600}" --memory-limit="${PAL_WORKER_MEMORY_LIMIT:-256M}" -vv
        ;;
    migrate)
        require APP_SECRET DATABASE_URL
        # Migrations may use a separate, more privileged database role (see deploy/sql/roles.sql).
        if [ -n "${DATABASE_URL_MIGRATIONS:-}" ]; then
            export DATABASE_URL="$DATABASE_URL_MIGRATIONS"
        fi
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        # Keep the audit trail append-only for the runtime role even when a migration has just
        # created it (default privileges would otherwise grant UPDATE/DELETE until roles.sql re-runs).
        if [ -n "${PAL_APP_DB_ROLE:-}" ]; then
            case "$PAL_APP_DB_ROLE" in
                *[!a-z0-9_]*|[!a-z_]*) echo "pal: PAL_APP_DB_ROLE must be a lowercase PostgreSQL identifier" >&2; exit 64 ;;
            esac
            php bin/console dbal:run-sql --quiet "REVOKE UPDATE, DELETE, TRUNCATE ON audit_event FROM \"$PAL_APP_DB_ROLE\""
            php bin/console dbal:run-sql --quiet "REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON doctrine_migration_versions FROM \"$PAL_APP_DB_ROLE\""
            echo "pal: audit trail revokes applied to role $PAL_APP_DB_ROLE"
        fi
        ;;
    console)
        exec php bin/console "$@"
        ;;
    *)
        echo "pal: unknown role '$role' (use web, worker, migrate or console)" >&2
        exit 64
        ;;
esac
