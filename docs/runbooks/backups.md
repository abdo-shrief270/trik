# Database backups

PostgreSQL is backed up by **pgBackRest**, inside the `postgres` container (`infra/production/postgres`), into two
repositories:

| | Where | Protects against | Keeps |
|---|---|---|---|
| **repo1** | the `pg_backup` volume on the server | a bad migration, a deleted table, a bad release | 2 weekly full backups and their WAL |
| **repo2** | an S3-compatible bucket off the server (optional, add it now) | losing the server or its disk | 4 weekly full backups and their WAL |

- **Continuous WAL archiving:** every change is shipped within about a minute (`archive_timeout=60`), so a restore
  loses at most the last minute. Measured: a commit was archived 61 s later and was in the restore.
- **Schedule** (cron, UTC, `/etc/cron.d/trik-backup`): full on Sunday 02:30, differential on other days at 02:30,
  a check every 5 minutes.
- **Encryption:** every file in both repositories is encrypted (AES-256) with `backup_cipher_pass` from the vault.
  **Keep a copy of that passphrase outside the server** (a password manager): without it, no backup can be read.
- **Restore drill:** `./backup.sh drill` restores into a throwaway container and checks it. On the test stack it
  took 6–13 s. Run it after every change to the image, and once a quarter.

Everything is on the server in `/srv/trik/infra/production`, run as `deploy`:

```bash
./backup.sh info            # what each repository holds, oldest and newest
./backup.sh check           # archiving lag and backup age (what cron runs)
./backup.sh diff            # a differential backup now (full: a full one)
./backup.sh drill [1|2]     # restore repo1 or repo2 into a throwaway container; prints counts and the time
journalctl -t trik-backup   # what cron's runs printed
```

## Add the off-server copy (Cloudflare R2)

1. Cloudflare → **R2** → *Create bucket* `trik-backups` (location hint: Eastern Europe). R2's free tier covers
   10 GB.
2. R2 → *Manage API tokens* → *Create API token*: **Object Read & Write**, only on `trik-backups`. Note the access
   key, the secret, and the S3 endpoint `https://<account-id>.r2.cloudflarestorage.com`.
3. In the vault (`ansible-vault edit vault.yml`): `backup_s3_bucket: trik-backups`,
   `backup_s3_endpoint: <account-id>.r2.cloudflarestorage.com` (no `https://`), `backup_s3_region: auto`,
   `backup_s3_key`, `backup_s3_key_secret`.
4. Run Ansible. It restarts PostgreSQL once (a few seconds), creates the stanza in the bucket and makes its first
   full backup there. Then `./backup.sh drill 2`.

Any S3-compatible storage works the same way (AWS S3 in an EU region, Backblaze B2, Wasabi…).

## Get told when something is wrong

`backup_alert_url` in the vault: a dead man's switch, e.g. a free [healthchecks.io](https://healthchecks.io) check
with *period 5 minutes, grace 15 minutes*. Every good check pings it, a bad one pings `/fail`, so it emails you both
when a backup or archiving fails and when the checks stop (the server is down). A check fails when:

- the oldest WAL segment waiting to be archived is more than 5 minutes old, or the last attempt failed;
- a repository's last backup is more than 26 hours old.

If archiving stays down, PostgreSQL keeps WAL up to 4 GiB (`archive-push-queue-max`) and then drops it rather than
filling the disk. The backups then have a gap until the next full backup, so fix it and run `./backup.sh full`.

## Restore

**First, stop and think.** Is it a whole-database problem? A single bad row is better fixed from a drill restore
(restore, copy the rows out, put them back) than by rolling the whole database back.

### Point in time (e.g. before a bad migration or a deleted table)

```bash
cd /srv/trik/infra/production
docker compose stop api worker scheduler edge       # nothing writes while you restore
docker compose stop postgres
docker compose run --rm --no-deps -u postgres --entrypoint trik-pgbackrest postgres \
  restore --delta --type=time --target="2026-12-01 09:59:00+00" --target-action=promote
docker compose up -d postgres && docker compose up -d
curl -s https://api.trik.chat/readyz
./backup.sh full                                     # a new timeline deserves a fresh full backup
```

Use `--repo=2` when the server's own repository is gone. Use no `--type` to replay to the latest change.

### New server (the old one is lost)

1. Set the new server up with Ansible from the same vault. Its `pg_backup` volume is empty and repo2 holds the
   backups. Stop before the first deploy.
2. Restore from repo2 into the empty `pg_data` volume:
   `docker compose run --rm --no-deps -u postgres --entrypoint trik-pgbackrest postgres restore --repo=2`
3. `docker compose up -d postgres`, then `./backup.sh init` and `./backup.sh full`, then deploy the last release
   (Actions → Deploy → Run workflow).

## Upgrading PostgreSQL or pgBackRest

`infra/production/postgres/Dockerfile` pins the pgBackRest release and its checksum. pgBackRest is built from source
because Alpine's stable package (2.58) rejects PostgreSQL 19.0. A major PostgreSQL upgrade needs a dump and restore
or `pg_upgrade`, plus `stanza-upgrade`: plan it, and run a drill afterwards.

Not done yet: a streaming replica on a second server (#352), for minutes instead of an hour of RTO.
