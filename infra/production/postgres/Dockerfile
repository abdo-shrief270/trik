# PostgreSQL with pgBackRest (WAL archiving and backups, docs/runbooks/backups.md). Ansible builds it on the server
# (`docker compose build postgres`); it is never pulled, so a release never restarts the database.
#
# pgBackRest is built from its release source: Alpine's stable package (2.58) rejects PostgreSQL 19.0's catalog.
# When you change either version, run `./backup.sh drill` afterwards.
ARG PG_IMAGE=postgres:19-alpine

FROM ${PG_IMAGE} AS pgbackrest
ARG PGBACKREST_VERSION=2.60.1
ARG PGBACKREST_SHA256=9a8aaf770e5e57c18426d5c2fa34e749db1ecb2d56979bf064550d5493a2df55
RUN apk add --no-cache build-base meson ninja pkgconf openssl-dev libxml2-dev lz4-dev zstd-dev bzip2-dev zlib-dev \
      yaml-dev libssh2-dev libpq-dev curl-dev curl py3-yaml \
 && curl -fsSL -o /tmp/pgbackrest.tar.gz \
      "https://github.com/pgbackrest/pgbackrest/archive/refs/tags/release/${PGBACKREST_VERSION}.tar.gz" \
 && echo "${PGBACKREST_SHA256}  /tmp/pgbackrest.tar.gz" | sha256sum -c - \
 && tar -xzf /tmp/pgbackrest.tar.gz -C /tmp \
 && meson setup /tmp/build "/tmp/pgbackrest-release-${PGBACKREST_VERSION}" --buildtype=release \
 && ninja -C /tmp/build \
 && install -m 0755 /tmp/build/src/pgbackrest /usr/local/bin/pgbackrest

FROM ${PG_IMAGE}
RUN apk add --no-cache libpq libxml2 lz4-libs zstd-libs libbz2 zlib yaml libssh2 \
 && install -d -o postgres -g postgres -m 0750 /var/lib/pgbackrest /var/spool/pgbackrest /var/log/pgbackrest
COPY --from=pgbackrest /usr/local/bin/pgbackrest /usr/local/bin/pgbackrest
COPY --chmod=0755 trik-pgbackrest /usr/local/bin/trik-pgbackrest
RUN pgbackrest version
