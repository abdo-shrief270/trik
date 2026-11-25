#!/usr/bin/env bash
# Checks that the tools needed for Trik development are installed.
set -uo pipefail

ok=0
check() {
  local name="$1" cmd="$2"
  if command -v "$cmd" >/dev/null 2>&1; then
    printf "  \033[32m✓\033[0m %-10s %s\n" "$name" "$($cmd --version 2>&1 | head -n1)"
  else
    printf "  \033[31m✕\033[0m %-10s not found\n" "$name"; ok=1
  fi
}

echo "Trik · checking tools"
check docker docker
check php php
check composer composer
check node node
check pnpm pnpm
check go go
check flutter flutter
check make make

[ -f .env ] || { cp .env.example .env && echo "  created .env from .env.example"; }
exit $ok
