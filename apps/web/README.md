# apps/web — Trik seller web app

Nuxt 4.7, Vue 3.5, TypeScript 5.9, Tailwind CSS. Arabic (RTL) and English from day one.

## Run it

```bash
pnpm install
pnpm dev                   # http://localhost:3000 (the API on :8080; see apps/api)
pnpm lint && pnpm typecheck && pnpm test
pnpm api:types             # after any change to packages/contracts/openapi/trik.v1.yaml (CI checks it)
```

A single-page app (`ssr: false`): it signs in with the Sanctum **session cookie**. On writes it fetches the
XSRF cookie once (`/sanctum/csrf-cookie`) and sends it back. Locally `localhost:3000` must be in the API's
`SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS`; in production the app is `app.trik.chat` with
`SESSION_DOMAIN=.trik.chat`. URLs have no language prefix (emails link to `/reset-password` and `/verified`);
the language lives in the `trik_locale` cookie.

## Talking to the API

```ts
const api = useApi()                                             // typed from the OpenAPI contract
const me = await call(api.GET('/auth/me'))                       // data, or throws ApiError
const orders = await call(api.GET('/orders', { params: { ...inWorkspace(), query: { status: 'new' } } }))
```

- `call()` turns every failure (problem+json, network, a proxy's HTML) into one `ApiError` with `status`,
  `code` and per-field `errors`. Show `t(problemMessageKey(e.problem))`: the API's English text is never shown
  to Arabic users.
- A 401 while signed in resets the session and opens sign-in with `?next=` (checked by `safeNext`).
- Writes get an `Idempotency-Key`; workspace endpoints take `inWorkspace()` (the header is required by the
  contract).
- Pages are signed-in only unless they say `definePageMeta({ auth: 'guest' | 'public' })`.

## What's built

Sign-up, sign-in, forgot and reset password, the email-verified page (#36); the shell with the icon rail,
workspace switcher with badges, account menu and verification banner (#35); home with where work is waiting
and a live setup checklist; settings with language and signed-in devices; base components in `layers/ui`
(#34); the typed client and error handling (#37). The **shared inbox** (`layers/inbox`): filters, conversations,
chat with the 24-hour window, replies, assignment, close/reopen, the order bar and the customer's orders with
confirm/cancel. The **confirmation board** (`layers/confirmations`): stages with counts, filters (owner,
governorate, risk, amount, dates), bulk confirm/remind/cancel with per-order results, an order drawer with the
WhatsApp confirmation run and the timeline, and the **confirmation rules** page. **Orders** (`layers/orders`): the
list (status and "address needs fixing" filters), the order page (items and totals, note, owner, address edit and
flag, confirm/cancel, confirmation run and timeline) and **New order** for orders taken on a call or in a chat.
**Settings** (`layers/settings`, with a section nav): account, **Stores** (connect Shopify or EasyOrders, the first import's
progress, what Trik writes back, disconnect, re-import; handles the platforms' redirects back), **Refusal risk** (threshold
and per-rule points), **Privacy requests** (store platforms' data and erasure requests, export download). Orders show their
refusal risk and top reasons (`OrderRisk`). Sections whose feature isn't rolled out for the workspace say so. Not yet: 2FA and team invites
(no API yet), dark mode, real-time (the inbox polls every 5–15 s, badges every minute, until the Centrifugo
connection-token endpoint exists).

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
