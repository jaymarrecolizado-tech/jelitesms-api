# Deploy / Portability Runbook (OPS / OWNER ONLY)

**Not for consumers.** Do not link this from Admin → Docs.

One codebase runs everywhere — only environment, database, and scheduling differ.
This page carries the concrete values for the live Hostinger deployment of
`jelitesmsapi.dictr2.cloud`.

## Target host

| Item | Value |
|------|-------|
| Domain | `https://jelitesmsapi.dictr2.cloud` |
| Site user | `dictr2-jelitesmsapi` |
| IP | `187.77.150.203` |
| Docroot | `/home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html` (confirm in File Manager) |
| Gateway | **Cloud only** — `https://api.sms-gate.app` (VPS cannot reach a phone LAN IP) |

## Build + upload

1. Rebuild staging from the repo root:

   ```
   C:\xampp\php\php.exe bin\export-prod.php
   ```

2. SFTP-upload the **contents** of `prod/jelite_sms_api/` into the docroot above
   (overwrite existing files on re-deploy). The package never contains `.env`,
   tests, examples, or ops docs.

3. Create `.env` on the server from `.env.example`:

   ```
   APP_URL=https://jelitesmsapi.dictr2.cloud
   APP_ENV=prod
   DB_HOST=127.0.0.1
   DB_USER=<hpanel_mysql_user>
   DB_PASS=<hpanel_mysql_password>
   # DB_NAME=<full_panel_db_name>   # only if panel name != jelite_sms_api_prod
   SMS_GATEWAY_URL=https://api.sms-gate.app
   SMS_API_PATH=/3rdparty/v1/messages
   ADMIN_USER=admin
   ADMIN_PASSWORD=<new_strong_password>
   ```
   chmod 600 .env

   Never reuse local dev/admin credentials. Create the MySQL DB (+user) in
   hPanel first; note its exact (prefixed) name and set `DB_NAME` if it differs
   from `jelite_sms_api_prod`.

4. Permissions, schema, cron:

   ```bash
   bash set-permissions.sh ~/domains/jelitesmsapi.dictr2.cloud/public_html
   cd ~/domains/jelitesmsapi.dictr2.cloud/public_html && php bin/setup.php
   ```

   Cron (hPanel → Cron Jobs), every minute:

   ```
   * * * * * php /home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html/bin/worker.php >> /home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html/storage/worker.log 2>&1
   * * * * * php /home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html/bin/sync-delivery.php >> /home/dictr2-jelitesmsapi/domains/jelitesmsapi.dictr2.cloud/public_html/storage/sync.log 2>&1
   ```

   (`bin/setup.php` applies `database/schema.sql` + migrations into the prod DB;
   it does not attempt `CREATE DATABASE` when `DB_NAME` is set.)

5. Go live:

   - Log into `/admin` with the new ADMIN_* creds.
   - Admin → Settings: cloud gateway username/password (from the Android app). Save.
   - Admin → Test → *Check configuration*: database up + gateway reachable.
   - Admin → API Keys: create one fresh key per consumer app (do not migrate local keys).
   - Point each consumer app's server-side `SMS_API_URL` / `SMS_API_KEY` at this base URL.

## Post-deploy verification

- [ ] `curl https://jelitesmsapi.dictr2.cloud/api/v1/health` → `200`, gateway reachable
- [ ] `.env`, `tests/`, `docs/ops/`, `storage/` not web-accessible (403/404)
- [ ] Admin login works over HTTPS only
- [ ] Test send via Admin → Test reaches a real phone
- [ ] Message transitions `queued → sent → delivered` within ~2 minutes (cron running)
- [ ] Consumer apps switched to the new base URL + keys

## Local Windows scheduling (dev)

```
powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1
```

Registers "JE Lite SMS Worker" every minute. Remove with
`Unregister-ScheduledTask -TaskName "JE Lite SMS Worker" -Confirm:$false`.
Re-run after moving the project folder.

## Rules that hold everywhere

- `.env` never leaves the machine; only `.env.example` ships/uploaded.
- API keys are stored hashed; plaintext shown once at creation.
- React/browser code never holds the Bearer key — backends only.
- On Hostinger the gateway must be the public cloud; LAN phone IPs are unreachable.
