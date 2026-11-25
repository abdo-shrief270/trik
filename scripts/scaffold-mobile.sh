#!/usr/bin/env bash
# Creates the Flutter app in apps/mobile, keeping lib/ structure.
set -euo pipefail
cd "$(dirname "$0")/../apps/mobile"
[ -f pubspec.yaml ] && { echo "apps/mobile already has a Flutter app"; exit 0; }
flutter create --org chat.trik --project-name trik --platforms ios,android .
flutter pub add drift drift_flutter flutter_riverpod go_router dio intl
flutter pub add --dev drift_dev build_runner
echo "Done."
