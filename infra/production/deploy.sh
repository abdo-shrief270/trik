#!/usr/bin/env bash
# Ships one release: ./deploy.sh <image-tag>   (run by .github/workflows/deploy.yml through the CI key's forced
# command, or by hand; to roll back: ./deploy.sh "$(cat .previous_tag)").
#
# pull → migrate (owner role) → switch → wait for the API's health → check readiness. If anything after the
# switch fails, the last release that passed these checks (.live_tag) comes back. Migrations are forward-only,
# so every migration must keep the previous release working (expand first, contract in a later release).
set -euo pipefail
cd "$(dirname "$0")"

tag="${1:?usage: deploy.sh <image-tag>}"
[[ "$tag" =~ ^[A-Za-z0-9._-]{1,128}$ ]] || { echo "invalid tag: $tag" >&2; exit 2; }

live="$(cat .live_tag 2>/dev/null || true)"
set_tag() { sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=$1/" .env; }
log() { printf '[deploy %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

fail() {
  log "FAILED: $1"
  if [[ -n "$live" ]]; then
    log "going back to ${live}"
    set_tag "$live"
    docker compose up -d --remove-orphans
  else
    log "no earlier good release to go back to; the stack is left as it is for debugging"
  fi
  exit 1
}

log "deploying ${tag} (live: ${live:-none})"
set_tag "$tag"

if ! docker compose pull --quiet --ignore-buildable; then
  set_tag "${live:-$tag}"
  log "pull failed; nothing changed"
  exit 1
fi

docker compose up -d postgres redis
if ! docker compose run --rm migrate; then
  set_tag "${live:-$tag}"
  log "migration failed; the running release was not touched"
  exit 1
fi

docker compose up -d --remove-orphans

log "waiting for the API to be healthy"
api="$(docker compose ps -q api)"
status=starting
for _ in $(seq 1 45); do
  status="$(docker inspect -f '{{.State.Health.Status}}' "$api" 2>/dev/null || echo starting)"
  [[ "$status" == healthy ]] && break
  sleep 2
done
[[ "$status" == healthy ]] || fail "the API did not become healthy (last status: ${status})"

# Readiness: database reachable, running as the restricted role (RLS), cache read-back.
docker compose exec -T api php -r '
  $ctx = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 5]]);
  @file_get_contents("http://127.0.0.1:8080/readyz", false, $ctx);
  exit(str_contains((string) ($http_response_header[0] ?? ""), " 200") ? 0 : 1);' \
  || fail "readiness check failed (see /readyz)"

if [[ -n "$live" && "$live" != "$tag" ]]; then echo "$live" > .previous_tag; fi
echo "$tag" > .live_tag
log "live: ${tag}"
