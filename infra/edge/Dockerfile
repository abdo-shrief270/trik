# syntax=docker/dockerfile:1.7
# Trik edge: Caddy serving the static landing site (apps/landing) and the web app (apps/web, a single-page app),
# and proxying the API. Build from the repo root:
#   docker build -f infra/edge/Dockerfile --build-arg WHATSAPP_NUMBER=2010xxxxxxxx \
#     --build-arg API_DOMAIN=api.trik.chat -t trik-edge .

FROM node:26-alpine AS landing
ARG WHATSAPP_NUMBER=""
ENV NUXT_PUBLIC_WHATSAPP_NUMBER=$WHATSAPP_NUMBER \
    NUXT_TELEMETRY_DISABLED=1
RUN npm install -g pnpm@12.23.0
WORKDIR /repo
# Design tokens are imported by the landing CSS.
COPY packages/design-tokens packages/design-tokens
COPY apps/landing/package.json apps/landing/pnpm-lock.yaml apps/landing/pnpm-workspace.yaml apps/landing/
WORKDIR /repo/apps/landing
RUN pnpm install --frozen-lockfile --ignore-scripts
COPY apps/landing .
RUN pnpm exec nuxt prepare && pnpm generate

# The web app is static (ssr: false): it talks to the API from the browser, with the session cookie.
FROM node:26-alpine AS web
ARG API_DOMAIN=api.trik.chat
ENV NUXT_PUBLIC_API_BASE=https://${API_DOMAIN}/v1 \
    NUXT_PUBLIC_CENTRIFUGO_URL=wss://${API_DOMAIN}/connection/websocket \
    NUXT_TELEMETRY_DISABLED=1
RUN npm install -g pnpm@12.23.0
WORKDIR /repo
COPY packages/design-tokens packages/design-tokens
COPY packages/contracts packages/contracts
COPY apps/web/package.json apps/web/pnpm-lock.yaml apps/web/pnpm-workspace.yaml apps/web/
WORKDIR /repo/apps/web
RUN pnpm install --frozen-lockfile --ignore-scripts
COPY apps/web .
RUN pnpm exec nuxt prepare && pnpm generate

FROM caddy:2-alpine
COPY infra/edge/Caddyfile /etc/caddy/Caddyfile
COPY infra/edge/cloudflare-ips.caddy /etc/caddy/cloudflare-ips.caddy
COPY --from=landing /repo/apps/landing/.output/public /srv/landing
COPY --from=web /repo/apps/web/.output/public /srv/app
EXPOSE 443
