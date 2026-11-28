#!/usr/bin/env bash
# Trik database backups (pgBackRest in the postgres container). docs/runbooks/backups.md.
#
#   ./backup.sh init        create the stanza in each repository, and a first full backup where there is none
#   ./backup.sh full|diff   back up to every configured repository (cron: full on Sunday, diff on other days)
#   ./backup.sh check       alert when WAL archiving lags or the last backup is too old (cron: every 5 minutes)
#   ./backup.sh drill [N]   restore repository N (default 1) into a throwaway container, check it, print the time
#   ./backup.sh info        what each repository holds
#
# Alerts: BACKUP_ALERT_URL in .env (a dead man's switch such as healthchecks.io) gets a ping after each good check
# and `/fail` on a bad one, so you hear about it both when backups fail and when the checks stop running.
set -euo pipefail
cd "$(dirname "$0")"

MAX_ARCHIVE_LAG=300        # seconds the oldest unarchived WAL segment may wait
MAX_BACKUP_AGE=$((26 * 3600))

log() { echo "[backup $(date -u +%H:%M:%S)] $*"; }   # cron sends all output to syslog: journalctl -t trik-backup
env_value() { sed -n "s/^$1=//p" .env | tail -1; }
pg() { docker compose exec -T -u postgres postgres "$@"; }
pgb() { pg trik-pgbackrest "$@"; }
sql() { pg psql -X -At -d trik -c "$1"; }

repos() {
  echo 1
  [ -n "$(env_value BACKUP_S3_BUCKET)" ] && echo 2
  return 0
}

ping_alert() {
  local url; url="$(env_value BACKUP_ALERT_URL)"
  [ -n "$url" ] || return 0
  curl -fsS -m 10 --retry 3 -o /dev/null "$url$1" --data-raw "${2:-}" || log "could not reach the alert URL"
}

backup() {
  local type=$1 failed=0
  for r in $(repos); do
    log "$type backup to repo$r"
    if pgb backup --repo="$r" --type="$type" --log-level-console=info; then
      log "repo$r: $type backup done"
    else
      log "repo$r: $type backup FAILED"; failed=1
    fi
  done
  [ "$failed" = 0 ] || { ping_alert /fail "backup $type failed"; exit 1; }
}

case "${1:-}" in
  init)
    # Idempotent: stanza-create skips a repository that already has the stanza, and a repository gets a full
    # backup only when it has none (e.g. the first run, or repo2 added later).
    pgb stanza-create --log-level-console=info
    pgb check --log-level-console=info
    for r in $(repos); do
      if [ "$(pgb info --repo="$r" --output=json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)[0]["backup"]))')" = 0 ]; then
        log "repo$r has no backup yet: full backup"
        pgb backup --repo="$r" --type=full --log-level-console=info
      fi
    done
    log "ready"
    ;;

  full|diff) backup "$1" ;;

  check)
    problems=()
    lag="$(sql "select coalesce(extract(epoch from now() - min(modification))::int, 0) from pg_ls_archive_statusdir() where name like '%.ready'")"
    [ "$lag" -le "$MAX_ARCHIVE_LAG" ] || problems+=("WAL archiving is ${lag}s behind")
    failing="$(sql "select coalesce(last_failed_time > coalesce(last_archived_time, 'epoch'), false) from pg_stat_archiver")"
    [ "$failing" = f ] || problems+=("the last WAL archive attempt failed")
    now=$(date +%s)
    for r in $(repos); do
      last="$(pgb info --repo="$r" --output=json | python3 -c 'import json,sys; b=json.load(sys.stdin)[0]["backup"]; print(b[-1]["timestamp"]["stop"] if b else 0)')"
      [ $((now - last)) -le "$MAX_BACKUP_AGE" ] || problems+=("repo$r: the last backup is older than 26 hours")
    done
    if [ ${#problems[@]} -gt 0 ]; then
      log "PROBLEM: ${problems[*]}"; ping_alert /fail "${problems[*]}"; exit 1
    fi
    ping_alert "" "ok"
    ;;

  drill)
    repo="${2:-1}"
    vol="trik-drill-$(date +%s)"
    start=$(date +%s)
    trap 'docker volume rm -f "$vol" >/dev/null 2>&1 || true' EXIT
    docker volume create "$vol" >/dev/null
    log "restoring repo$repo into a throwaway container"
    # Not the live server: no network, no archiving (it must never push WAL into the repositories).
    docker compose run --rm --no-deps -T -u postgres -v "$vol:/var/lib/postgresql" --entrypoint sh postgres -ec '
      trik-pgbackrest restore --repo='"$repo"' --type=default --archive-mode=off --log-level-console=warn
      # Recovery refuses to start with fewer max_connections than the live server.
      postgres -D "$PGDATA" -c archive_mode=off -c listen_addresses= -c shared_buffers=256MB -c max_connections=300 \
        >/tmp/pg.log 2>&1 &
      pid=$!
      for i in $(seq 1 1800); do
        [ "$(psql -X -At -d trik -c "select pg_is_in_recovery()" 2>/dev/null)" = f ] && break
        kill -0 "$pid" 2>/dev/null || break
        sleep 1
      done
      psql -X -At -d trik -c "select pg_is_in_recovery()" | grep -qx f || { tail -20 /tmp/pg.log; exit 1; }
      echo "tables: $(psql -X -At -d trik -c "select count(*) from pg_tables where schemaname not in ('"'pg_catalog'"', '"'information_schema'"')")"
      echo "users:  $(psql -X -At -d trik -c "select count(*) from users" 2>/dev/null || echo "n/a")"
      echo "newest user: $(psql -X -At -d trik -c "select max(created_at) from users" 2>/dev/null || echo "n/a")"
      pg_ctl -D "$PGDATA" -m fast stop >/dev/null
    '
    log "drill OK: repo$repo restored and opened in $(( $(date +%s) - start ))s"
    ;;

  info) pgb info ;;

  *) sed -n '2,12p' "$0"; exit 2 ;;
esac
