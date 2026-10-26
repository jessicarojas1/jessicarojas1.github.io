#!/bin/sh
# First-start initialisation for the bundled PostgreSQL container (deploy/compose.yaml).
# Creates the owner and app roles from deploy/sql/roles.sql and sets their passwords
# from Docker secrets, so no password appears in a file under version control.
set -eu

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -v owner_role="$PAL_OWNER_ROLE" -v app_role="$PAL_APP_ROLE" -v db_name="$POSTGRES_DB" \
    -f /sql/roles.sql

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
    -v owner_role="$PAL_OWNER_ROLE" -v app_role="$PAL_APP_ROLE" \
    -v owner_pw="$(cat /run/secrets/pal_db_owner_password)" \
    -v app_pw="$(cat /run/secrets/pal_db_app_password)" <<'SQL'
ALTER ROLE :"owner_role" PASSWORD :'owner_pw';
ALTER ROLE :"app_role" PASSWORD :'app_pw';
SQL
