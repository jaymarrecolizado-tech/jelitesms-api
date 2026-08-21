# Deploy / Portability Runbook

One codebase runs everywhere — only environment, database, and scheduling differ.
Local XAMPP today; Hostinger VPS later (Phase 6). Nothing in the code changes on upload.

## What differs per environment

| Setting | Local XAMPP (now) | Hostinger VPS (later) |
|---------|-------------------|------------------------|
| `APP_URL` | `http://localhost/projects/jelite_sms_api` | `https://sms-api.yourdomain.com` |
| `APP_ENV` | `dev` | `prod` (DB becomes `jelite_sms_api_prod`) |
| `DB_*` | local MySQL (root) | Hostinger MySQL credentials |
| Gateway mode | cloud or LAN phone | **cloud only** (`https://api.sms-gate.app`) |
| Worker schedule | Windows Task Scheduler (`bin/register-worker-task.ps1`) | cron (snippet below) |
| Admin UI | `/admin` under install base | same paths under new base URL |

## Upload checklist (when Phase 6 starts)

1. **Never commit or upload `.env`.** Upload the repo as-is; create a fresh `.env` on the server from `.env.example`.
2. Set on the server `.env`:
   - `APP_ENV=prod`
   - `APP_URL=https://sms-api.yourdomain.com`
   - Hostinger `DB_HOST/DB_USER/DB_PASS`
   - `SMS_GATEWAY_URL=https://api.sms-gate.app`, `SMS_API_PATH=/3rdparty/v1/messages`
   - New `ADMIN_USER` / `ADMIN_PASSWORD` (do not reuse local ones)
3. Create the schema: `php bin/setup.php` (creates `jelite_sms_api_prod` + tables).
4. Log into `/admin`, enter gateway credentials in **Settings** (or `.env`), and verify **Test → Check configuration**.
5. Create fresh API keys per consumer app (**Admin → API Keys**). Do not migrate local keys.
6. Point each consumer app's server-side config at the new base URL + key (see [CONSUMERS.md](CONSUMERS.md)).
7. Schedule the worker (cron snippet below) and confirm messages flow.

## Worker scheduling

### Local Windows (Task Scheduler)

```
powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1
```

Registers "JE Lite SMS Worker" running every minute:

```
C:\xampp\php\php.exe C:\xampp\htdocs\Projects\jelite_sms_api\bin\worker.php
```

Re-run the script after moving the project folder. Remove with
`Unregister-ScheduledTask -TaskName "JE Lite SMS Worker" -Confirm:$false`.

### Linux / Hostinger (cron)

```
* * * * * php /path/to/jelite_sms_api/bin/worker.php >> /path/to/jelite_sms_api/worker.log 2>&1
* * * * * php /path/to/jelite_sms_api/bin/sync-delivery.php >> /path/to/jelite_sms_api/sync.log 2>&1
```

`bin/sync-delivery.php` polls the gateway for delivery states (`delivered` / terminal `failed`) of already-sent messages; the worker also runs it after each drain, so a separate schedule is optional but recommended. Ensure log files are writable by the PHP user and not web-accessible (or write outside the docroot).

## Rules that hold everywhere

- `.env` never leaves the machine; only `.env.example` is in git.
- API keys are stored hashed; plaintext shown once at creation.
- React/browser code never holds the Bearer key — backends only.
- On Hostinger, gateway must be the public cloud; a LAN phone IP is unreachable from the VPS.
