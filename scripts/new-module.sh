#!/usr/bin/env bash
# Creates a backend module skeleton: ./scripts/new-module.sh Returns
# - folders for Domain / Application / Infrastructure / Http, migrations and tests
# - Modules\<Name>\<Name>ServiceProvider (picked up automatically by ModulesServiceProvider)
# - the module's PSR-4 entries in apps/api/composer.json
set -euo pipefail
name="${1:?Usage: new-module.sh <ModuleName in PascalCase>}"
[[ "$name" =~ ^[A-Z][A-Za-z0-9]+$ ]] || { echo "Module name must be PascalCase"; exit 1; }

api="$(cd "$(dirname "$0")/../apps/api" && pwd)"
base="$api/modules/$name"
[ -d "$base" ] && { echo "Module $name already exists"; exit 1; }

for d in src/Domain src/Application src/Infrastructure src/Http database/migrations tests/Unit tests/Feature; do
  mkdir -p "$base/$d" && touch "$base/$d/.gitkeep"
done

cat > "$base/src/${name}ServiceProvider.php" <<PHP
<?php

declare(strict_types=1);

namespace Modules\\${name};

use App\\Support\\Modules\\ModuleServiceProvider;

/**
 * Registered automatically. Migrations in database/migrations and routes in
 * src/Http/routes.php (mounted under /v1) are loaded by the base class.
 */
final class ${name}ServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }
}
PHP

cat > "$base/README.md" <<MD
# $name

<What this module is responsible for, in one sentence.>

## Public API (Application layer)

| Contract | Purpose |
|---|---|

## Events

| Emits | Listens to |
|---|---|
MD

if [ -f "$api/composer.json" ]; then
  php -r '
    [$file, $n] = [$argv[1], $argv[2]];
    $c = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $c["autoload"]["psr-4"]["Modules\\$n\\"] = "modules/$n/src/";
    $c["autoload-dev"]["psr-4"]["Modules\\$n\\Tests\\"] = "modules/$n/tests/";
    file_put_contents($file, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
  ' "$api/composer.json" "$name"
  (cd "$api" && composer dump-autoload -q)
fi

echo "Created apps/api/modules/$name"
