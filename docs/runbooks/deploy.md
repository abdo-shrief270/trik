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

## First setup (once): the launch checklist

Three addresses, all behind Cloudflare: `trik.chat` (landing), `app.trik.chat` (the web app), `api.trik.chat` (API and
real time). The server's IP is never published: Cloudflare is in front before the first DNS record exists.

0. **Code on `main`.** Merge the open PRs in the order listed in `CLAUDE.md` ("Where things stand"); the last one is the
   launch PR. Releases deploy from `main`.
1. **Domain (Hostinger → Domains → trik.chat):**
   - turn **privacy protection (WHOIS privacy)** on;
   - remove Hostinger's default DNS records for the domain (parking page, mail), if any.
2. **Cloudflare** (a free account; add `trik.chat`, then set the two Cloudflare nameservers in Hostinger → Domains →
   DNS / Nameservers → *Change nameservers*; it takes minutes to a few hours):
   - SSL/TLS: **Full (strict)**, Always Use HTTPS, minimum TLS 1.2.
   - SSL/TLS → Origin Server → **Create certificate** for `trik.chat, *.trik.chat` (15 years). Keep the certificate and
     key for the vault.
   - Don't add `A` records yet (step 8).
3. **Server (Hostinger → VPS → KVM 4):**
   - OS: **Ubuntu 24.04** (a fresh install wipes the disk), with your SSH public key added for root;
   - note the IP; in the VPS **firewall** allow only TCP 22 and 443 (Ansible locks 443 to Cloudflare's ranges);
   - take a **snapshot** after step 6 (until pgBackRest backups exist).
4. **Email, sending only** (verification and password resets; no mailbox needed):
   - a free account at a transactional provider (Resend: 3,000 emails a month free; or Brevo);
   - add the domain `trik.chat` there and copy its **DNS records** (SPF `TXT`, DKIM `TXT`/`CNAME`, and a DMARC `TXT`
     such as `v=DMARC1; p=quarantine`) into Cloudflare, **DNS only** (grey cloud) for these;
   - once verified, the SMTP host, user and password go into the vault (`mail_smtp_*`).
   Without it, `MAIL_MAILER=log`: nobody can verify their email, so keep sign-up to people you set up by hand.
5. **GitHub**:
   - A fine-grained token with *packages: read* for the server (`ghcr_token`).
   - An SSH key pair for CI: `ssh-keygen -t ed25519 -f trik-ci -C trik-ci`. The private key goes in the
     repository secret `DEPLOY_SSH_KEY`, the public key in `ci_deploy_key` (vars.yml).
   - Environment **production**: add yourself as a required reviewer (each release waits for your approval).
6. **Ansible** (from any machine with Ansible ≥ 2.17 and the `community.general` and `ansible.posix` collections):
   ```bash
   cd infra/ansible
   cp inventory.example.ini inventory.ini        # the server's IP
   cp vault.example.yml vault.yml                # fill in, then: ansible-vault encrypt vault.yml
   # vars.yml: deploy_ssh_keys (your public key), ci_deploy_key
   ansible-playbook -i inventory.ini site.yml --ask-vault-pass          # first run, as root
   ```
   From then on root SSH is off: `-e ansible_user=deploy`.
7. **Deploy secrets in GitHub**: `DEPLOY_HOST` (the IP), `DEPLOY_KNOWN_HOSTS`
   (`ssh-keyscan -t ed25519 <ip>`), and the variables `WHATSAPP_NUMBER` and `DEPLOY_ENABLED=true`.
   Run the **Deploy** workflow. The first run creates the database, its three roles and all tables.
8. **DNS** in Cloudflare, all **proxied** (orange cloud): `A trik.chat → <ip>`, `CNAME www → trik.chat`,
   `A app → <ip>`, `A api → <ip>`. Check `https://trik.chat`, `https://app.trik.chat` and
   `https://api.trik.chat/readyz`.
9. **Pilot switches:** `feature_commerce` and `feature_risk` in `vars.yml` are `none`; set them to the pilot workspace
   ids (comma-separated) or `all`, and the Shopify app's `shopify_client_id` / `shopify_client_secret` in the vault
   (Commerce README). Re-run Ansible (it rewrites `app.env`; don't edit it by hand), then on the server
   `docker compose up -d api worker scheduler`.

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

- **Email:** set up in step 4 (otherwise `MAIL_MAILER=log`: verification and password resets don't go out).
- **Backups:** pgBackRest to S3 (ADR 0011) is not set up yet. Until it is, take a Hostinger snapshot
  before each release that has migrations.
- **Monitoring:** uptime checks from outside (KVM 1) are not set up yet.

## Tested

The full stack was deployed with `deploy.sh` from a registry (first deploy, an upgrade, and a broken release
that rolled back by itself); checked through the edge: landing pages, 404, cache headers, `www` redirect,
health and readiness, sign-up, queue worker, scheduler as owner, forged `X-Forwarded-For` (per-IP limit still
applies), 5 MB body (413), WebSocket (101), unknown host refused. Ansible: syntax check and `ansible-lint`
(production profile). Not yet run against a real server.
