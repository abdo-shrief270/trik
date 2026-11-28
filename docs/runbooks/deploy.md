# Production: first setup, releases, rollback

One server (Hostinger KVM 4, EU) runs everything for the pilot (ADR 0011): PostgreSQL 19, Redis 8, the API
under Octane (FrankenPHP), queue workers, the scheduler, Centrifugo, and the edge (Caddy + the landing site).
Cloudflare sits in front; the server accepts HTTPS **only from Cloudflare** and is never addressed directly.

| What | Where |
|---|---|
| Server setup (users, SSH, firewall, Docker, files, secrets) | `infra/ansible` (re-runnable) |
| Services | `infra/production/compose.yml` → `/srv/trik/infra/production` on the server |
| Releases | `.github/workflows/deploy.yml` → `deploy.sh <tag>` on the server |
| Edge (TLS, routing, headers, landing) | `infra/edge/` (built into the `trik-edge` image) |

## First setup (once)

1. **Cloudflare** (proxied, orange cloud), with `trik.chat` already on Cloudflare's nameservers:
   - SSL/TLS: **Full (strict)**, Always Use HTTPS, minimum TLS 1.2.
   - SSL/TLS → Origin Server → **Create certificate** for `trik.chat, *.trik.chat` (15 years). Keep the
     certificate and key for the vault.
   - Don't add DNS records yet (step 5).
2. **GitHub**:
   - A fine-grained token with *packages: read* for the server (`ghcr_token`).
   - An SSH key pair for CI: `ssh-keygen -t ed25519 -f trik-ci -C trik-ci`. The private key goes in the
     repository secret `DEPLOY_SSH_KEY`, the public key in `ci_deploy_key` (vars.yml).
   - Environment **production**: add yourself as a required reviewer (each release waits for your approval).
3. **Ansible** (from any machine with Ansible ≥ 2.17 and the `community.general` and `ansible.posix` collections):
   ```bash
   cd infra/ansible
   cp inventory.example.ini inventory.ini        # the server's IP
   cp vault.example.yml vault.yml                # fill in, then: ansible-vault encrypt vault.yml
   # vars.yml: deploy_ssh_keys (your public key), ci_deploy_key
   ansible-playbook -i inventory.ini site.yml --ask-vault-pass          # first run, as root
   ```
   From then on root SSH is off: `-e ansible_user=deploy`.
4. **Deploy secrets in GitHub**: `DEPLOY_HOST` (the IP), `DEPLOY_KNOWN_HOSTS`
   (`ssh-keyscan -t ed25519 <ip>`), and the variables `WHATSAPP_NUMBER` and `DEPLOY_ENABLED=true`.
   Run the **Deploy** workflow. The first run creates the database, its three roles and all tables.
5. **DNS** in Cloudflare, all **proxied**: `A trik.chat → <ip>`, `CNAME www → trik.chat`,
   `A api → <ip>`. Check `https://trik.chat` and `https://api.trik.chat/readyz`.

## Releases

Merging to `main` builds `trik-api` and `trik-edge` (tag = first 12 characters of the commit), waits for
approval in the *production* environment, then runs `deploy.sh <tag>` on the server:

1. pull the images;
2. `migrate` as the owner role (a failure stops here; the running release is untouched);
3. switch all services;
4. wait for the API's health check, then `/readyz` (database, **running as `trik_app`**, cache);
5. on failure, go back to the last release that passed (`.live_tag`).

**Migrations must keep the previous release working** (add columns and tables first; remove them in a
later release), because a rollback does not undo them.

## Rollback by hand

```bash
ssh deploy@<ip>
cd /srv/trik/infra/production
./deploy.sh "$(cat .previous_tag)"        # or any earlier commit tag
```

Or run the **Deploy** workflow with the *tag* input.

## Everyday commands (on the server, in /srv/trik/infra/production)

```bash
docker compose ps                         # what runs, health
docker compose logs -f api worker         # logs (also: scheduler, edge, centrifugo, postgres)
docker compose exec api php artisan tinker
docker compose exec postgres psql -U postgres trik
```

## Security model, briefly

- The edge is the only published port (443), and only Cloudflare's ranges reach it, for the host and for
  Docker's own forwarding (`DOCKER-USER`). SSH is keys-only, no root, `deploy` only, fail2ban.
- The CI key is locked to `ci-deploy`: it can run `deploy <tag>` and nothing else.
- The API and workers log in as `trik_api` and work as `trik_app` (row-level security). Only `migrate` and
  `scheduler` get the owner password. The superuser is for administration only.
- The edge replaces `X-Forwarded-For` with the visitor's IP from Cloudflare, so per-IP limits can't be
  dodged with a forged header; the API trusts only the edge (`TRUSTED_PROXIES=172.30.0.10`).
- Secrets live in the Ansible vault and on the server (`.env`, `app.env`, `certs/`, all `0600`), never in Git.

## Before public sign-up

- **Email:** `MAIL_MAILER=log` until a provider is set (verification and password reset need it).
- **Backups:** pgBackRest to S3 (ADR 0011) is not set up yet. Until it is, take a Hostinger snapshot
  before each release that has migrations.
- **Monitoring:** uptime checks from outside (KVM 1) are not set up yet.

## Tested

The full stack was deployed with `deploy.sh` from a registry (first deploy, an upgrade, and a broken release
that rolled back by itself); checked through the edge: landing pages, 404, cache headers, `www` redirect,
health and readiness, sign-up, queue worker, scheduler as owner, forged `X-Forwarded-For` (per-IP limit still
applies), 5 MB body (413), WebSocket (101), unknown host refused. Ansible: syntax check and `ansible-lint`
(production profile). Not yet run against a real server.
