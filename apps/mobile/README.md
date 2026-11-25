# apps/mobile — Trik mobile apps

Flutter (stable channel) for iOS and Android. Offline-first with **Drift** (SQLite), so agents can read and queue replies on weak connections.

## Create the app (first time only)

```bash
make scaffold-mobile       # or: ./scripts/scaffold-mobile.sh
```

This runs `flutter create --org chat.trik --project-name trik .` and keeps the `lib/` structure below.

## Layout (feature-first, clean architecture)

```
lib/
├── app/                   # App entry, router, dependency injection
├── core/
│   ├── network/           # API client (generated from packages/contracts), auth, retry
│   ├── storage/           # Drift database, offline queue
│   ├── realtime/          # Centrifugo client
│   ├── theme/             # Trik tokens: colours, type, spacing
│   └── l10n/              # Arabic and English
└── features/
    ├── inbox/             # data / domain / presentation
    ├── confirmations/
    ├── orders/
    ├── analytics/
    └── settings/
```
