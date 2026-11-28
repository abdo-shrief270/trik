# syntax=docker/dockerfile:1.7
# Trik edge: Caddy serving the static landing site (apps/landing) and proxying the API.
# Build from the repo root:
#   docker build -f infra/edge/Dockerfile --build-arg WHATSAPP_NUMBER=2010xxxxxxxx -t trik-edge .

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

FROM caddy:2-alpine
COPY infra/edge/Caddyfile /etc/caddy/Caddyfile
COPY infra/edge/cloudflare-ips.caddy /etc/caddy/cloudflare-ips.caddy
COPY --from=landing /repo/apps/landing/.output/public /srv/landing
EXPOSE 443
