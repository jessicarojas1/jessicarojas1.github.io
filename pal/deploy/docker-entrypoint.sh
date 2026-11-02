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

# Platforms that replace the image ENTRYPOINT with their start command (some PaaS) pass this
# script's own path first; drop it so "pal-entrypoint web" works either way.
case "${1:-}" in
    */pal-entrypoint|pal-entrypoint) shift ;;
esac

# Shared database: keep PAL's objects in their own schema (same convention as the repository's
# other apps). libpq reads PGOPTIONS, so this covers both the DBAL and the session connections.
if [ -n "${DB_SCHEMA:-}" ]; then
    case "$DB_SCHEMA" in
        *[!a-z0-9_]*|[!a-z_]*) echo "pal: DB_SCHEMA must be a lowercase PostgreSQL identifier" >&2; exit 64 ;;
    esac
    export PGOPTIONS="--search_path=$DB_SCHEMA,public"
fi

migrate() {
    require APP_SECRET DATABASE_URL
    # Migrations may use a separate, more privileged database role (see deploy/sql/roles.sql).
    if [ -n "${DATABASE_URL_MIGRATIONS:-}" ]; then
        export DATABASE_URL="$DATABASE_URL_MIGRATIONS"
    fi
    if [ -n "${DB_SCHEMA:-}" ]; then
        php bin/console dbal:run-sql --quiet "CREATE SCHEMA IF NOT EXISTS \"$DB_SCHEMA\""
    fi
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    # Create the first local administrator from PAL_BOOTSTRAP_LOCAL_ADMIN_* (only if none exists).
    php bin/console pal:users:bootstrap-admin
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
}

role="${1:-web}"
[ "$#" -gt 0 ] && shift

case "$role" in
    web)
        require APP_SECRET DATABASE_URL PAL_BASE_URL TRUSTED_HOSTS
        # For platforms with no pre-deploy step (single instance only: migrations here are not
        # coordinated between containers). Elsewhere run the migrate role separately.
        # The subshell keeps DATABASE_URL_MIGRATIONS out of the web process.
        if [ "${PAL_MIGRATE_ON_START:-0}" = "1" ]; then
            ( migrate )
        fi
        exec supervisord -c /etc/supervisord.conf
        ;;
    worker)
        require APP_SECRET DATABASE_URL
        # Exit periodically so memory is reclaimed; the orchestrator restarts the container.
        exec php bin/console messenger:consume async scheduler_default \
            --time-limit="${PAL_WORKER_TIME_LIMIT:-3600}" --memory-limit="${PAL_WORKER_MEMORY_LIMIT:-256M}" -vv
        ;;
    migrate)
        migrate
        ;;
    console)
        exec php bin/console "$@"
        ;;
    *)
        echo "pal: unknown role '$role' (use web, worker, migrate or console)" >&2
        exit 64
        ;;
esac
