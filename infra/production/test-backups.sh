#!/usr/bin/env bash
# Smoke test for the backups (CI: .github/workflows/infra.yml; also safe to run on any machine with Docker).
# Builds the postgres image, starts it alone from a copy of infra/ with throwaway secrets, then: init, check,
# a differential, and a restore drill that must find every committed row, archived WAL included.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d)"
project="trikbackuptest$$"
cleanup() {
  docker compose -p "$project" --project-directory "$work/infra/production" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

cp -r "$here/.." "$work/infra"
cd "$work/infra/production"
cat > .env <<EOF
COMPOSE_PROJECT_NAME=$project
IMAGE_REPO=unused
IMAGE_TAG=unused
POSTGRES_SUPERUSER_PASSWORD=$(openssl rand -hex 16)
DB_OWNER_PASSWORD=$(openssl rand -hex 16)
DB_API_PASSWORD=$(openssl rand -hex 16)
REDIS_PASSWORD=unused
CENTRIFUGO_API_KEY=unused
CENTRIFUGO_TOKEN_HMAC_SECRET=unused
PG_SHARED_BUFFERS=128MB
PG_EFFECTIVE_CACHE_SIZE=512MB
PG_MEMORY=1g
BACKUP_CIPHER_PASS=$(openssl rand -hex 32)
EOF
touch app.env

q() { docker compose exec -T -u postgres postgres psql -X -At -d trik -c "$1"; }
step() { echo "== $*"; }

step "build and start"
docker compose build -q postgres
docker compose up -d --wait postgres
q "create table users (id serial primary key, created_at timestamptz default now())" >/dev/null
q "insert into users default values" >/dev/null

step "init, check, differential"
./backup.sh init
./backup.sh check
q "insert into users default values" >/dev/null
./backup.sh diff

step "a commit after the last backup, shipped only as WAL"
q "insert into users default values" >/dev/null
q "select pg_switch_wal()" >/dev/null
wal="$(q "select pg_walfile_name(pg_current_wal_lsn() - 1)")"
for _ in $(seq 1 60); do
  [ "$(q "select coalesce(last_archived_wal, '') >= '$wal' from pg_stat_archiver")" = t ] && break
  sleep 2
done
expected="$(q "select count(*) from users")"

step "drill"
out="$(./backup.sh drill 1)"
echo "$out"
got="$(echo "$out" | sed -n 's/^users: *//p')"
if [ "$got" != "$expected" ]; then
  echo "FAIL: the restore has $got users, the database $expected"; exit 1
fi
echo "OK: the restore has all $expected rows"
