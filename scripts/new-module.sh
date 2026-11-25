#!/usr/bin/env bash
# Creates a backend module skeleton: ./scripts/new-module.sh Returns
set -euo pipefail
name="${1:?Usage: new-module.sh <ModuleName in PascalCase>}"
base="$(dirname "$0")/../apps/api/modules/$name"
[ -d "$base" ] && { echo "Module $name already exists"; exit 1; }

for d in src/Domain src/Application src/Infrastructure src/Http database/migrations tests/Unit tests/Feature; do
  mkdir -p "$base/$d" && touch "$base/$d/.gitkeep"
done
cat > "$base/README.md" <<EOF
# $name

<What this module is responsible for, in one sentence.>

## Public API (Application layer)

| Contract | Purpose |
|---|---|

## Events

| Emits | Listens to |
|---|---|
EOF
echo "Created apps/api/modules/$name"
