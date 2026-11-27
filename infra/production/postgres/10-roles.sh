#!/bin/sh
# First start only (docker-entrypoint-initdb.d): creates trik_owner, trik_app and trik_api from
# infra/postgres/roles.sql with the passwords from the environment. The superuser (postgres) stays for
# administration only; the apps never use it.
set -eu
: "${TRIK_OWNER_PASSWORD:?}" "${TRIK_API_PASSWORD:?}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname trik \
  -v owner_password="$TRIK_OWNER_PASSWORD" \
  -v api_password="$TRIK_API_PASSWORD" \
  -f /trik/roles.sql
