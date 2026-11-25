#!/usr/bin/env bash
# Creates the Nuxt 4 app in apps/web, keeping layers/ and i18n/.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f apps/web/package.json ] && { echo "apps/web already has a Nuxt app"; exit 0; }

tmp="$(mktemp -d)"
npx --yes nuxi@latest init "$tmp/web" --packageManager pnpm --gitInit false
cp -rn "$tmp/web/." apps/web/
rm -rf "$tmp"

cd apps/web
pnpm add @nuxtjs/i18n @pinia/nuxt pinia
pnpm add -D @nuxt/eslint vitest @vue/test-utils @playwright/test typescript vue-tsc
echo "Done. Add the layers to nuxt.config.ts: extends: ['./layers/ui', './layers/inbox', ...]"
