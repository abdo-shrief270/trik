# apps/landing — trik.chat

The marketing site: a static Nuxt 4 site, Arabic first (`/`), English under `/en`. Built with `nuxt generate`
and served as plain files from any static host or CDN.

```bash
pnpm install
pnpm dev          # http://localhost:3100
pnpm lint && pnpm typecheck && pnpm test
pnpm generate     # static site in .output/public
```

## Pages

| Route | What |
|---|---|
| `/` | Home: live chat hero, order ticker, the problem, the pinned "road" (Chat → Profit), live confirmation board, refusal calculator, features, waitlist band |
| `/features`, `/features/<slug>` | Overview and one page per feature (`confirmation`, `risk`, `inbox`, `stores`), each with its own animated visual |
| `/how-it-works` | Four steps with a sticky panel that follows the step in view |
| `/pricing` | Plan finder (orders a month → plan), plans, FAQ |
| `/waitlist`, `/waitlist/check-email` | The waitlist form (double opt-in) and the "check your email" step |
| `/waitlist/confirmed`, `/waitlist/unsubscribed` | Where the email links land (prerendered explicitly: nothing links to them) |

Every route exists in both languages (`/en/...`). Copy lives only in `i18n/locales/{ar,en}.json`; keep the two
in step. Arabic uses the Egyptian voice of the brand book.

## Motion

- **Reveal on scroll:** `v-reveal` (optionally `v-reveal="120"` for a delay). Rendered on the server; content is
  never hidden without JavaScript.
- **Smooth scroll:** Lenis (`plugins/motion.client.ts`), off under `prefers-reduced-motion`.
- **Pinned horizontal scroll:** `RoadSection` loads GSAP + ScrollTrigger on demand, only on wide screens with
  motion allowed, so no other page or phone downloads it.
- **Demos:** `HeroChat`, `BoardDemo`, `visual/*` run on timers that pause off-screen and in background tabs,
  and show their final state when motion is reduced.
- **Budget:** decorative glows are static (an animated blur cost ~1.7 s of main thread on a GPU-less device);
  the marquee is transform-only on its own layer and pauses off-screen. Check with the long-task numbers in
  DevTools before adding anything that animates forever.

## Brand rules that the code follows

Tokens come from `packages/design-tokens`. Lime only marks a confirmed order (the third tick, the Trik'd label)
and never sits on a light background. The tick mark sits left of the word in both languages. Arabic text uses
IBM Plex Sans Arabic throughout. Fonts are self-hosted at build time (`@nuxt/fonts`).

## Waitlist

`POST {apiBase}/waitlist` with an `Idempotency-Key`, JSON body built by `utils/waitlist.ts#waitlistPayload`
(phone normalised to E.164, Arabic digits accepted). A 422 problem+json `errors` map is shown per field.
Set the API with `NUXT_PUBLIC_API_BASE` at build time. The API side is `apps/api/modules/Waitlist` (double
opt-in): its emailed links land on `/waitlist/confirmed`, `/waitlist/unsubscribed`, or back on `/waitlist?link=expired`.
The API's `LANDING_URL` must point at this site and `CORS_ALLOWED_ORIGINS` must include its origin.

## Placeholders to settle

- Plan bundles in `utils/plans.ts` (300 / 1,500 / 5,000) and the team sizes in the plan copy are drafts until
  pricing is set with the pilot sellers (#147, #148). Prices show "Pilot pricing".
- Contact address in the footer: `hello@trik.chat`.
- Legal pages (#175), an Open Graph image (#176) and analytics (#177) are not in this app yet.
