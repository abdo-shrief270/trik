# apps/web — Trik seller web app

Nuxt 4, Vue 3, TypeScript, Tailwind CSS. Arabic (RTL) and English from day one.

## Create the app (first time only)

```bash
make scaffold-web          # or: ./scripts/scaffold-web.sh
```

## Layout

The app is split into **Nuxt layers**, one per product area, so each area owns its pages, components and stores.

```
apps/web/
├── app/                   # Shell: app.vue, default layout, global middleware
├── layers/
│   ├── ui/                # Design system: Trik tokens, base components (Button, Chip, Table, Drawer…)
│   ├── auth/              # Sign-in, workspace switcher
│   ├── onboarding/        # WhatsApp signup, store, gateway, couriers, rules, team
│   ├── inbox/             # Conversations, order bar, side panel
│   ├── confirmations/     # Confirmation desk, ladder, call queue
│   ├── orders/            # Order list and order page
│   ├── campaigns/         # Broadcasts, templates, segments
│   ├── automations/       # Flow builder
│   ├── analytics/         # Profit, funnel, couriers, team
│   └── settings/          # Workspace, billing, developers, compliance
├── i18n/locales/          # ar.json, en.json
├── public/
└── tests/                 # Vitest unit tests, Playwright end-to-end tests
```

## Rules

- Colours, fonts and spacing come only from `packages/design-tokens`. No hard-coded hex values.
- Every string goes through i18n. Use logical CSS properties (`margin-inline-start`) so RTL works without extra code.
- Server state through a typed API client generated from `packages/contracts/openapi`.
- Real-time updates through Centrifugo channels, never polling.
