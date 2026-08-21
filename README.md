# JE Lite SMS API

Standalone PHP + MySQL HTTP service that wraps [SMS Gateway for Android](https://github.com/capcom6/android-sms-gateway) ([sms-gate.app](https://sms-gate.app)) so Laravel, CodeIgniter, React backends, HRMIS, and other DICT apps can send SMS with an API key.

- Send model: **enqueue + worker** — the HTTP call never blocks on the gateway.
- API keys are stored **hashed**; consumers authenticate with `Authorization: Bearer <key>`.
- Separate databases per environment: `jelite_sms_api_dev` / `_test` / `_prod` (from `APP_ENV`).
- React/SPA must never call this API directly — only server-side backends hold keys.

## Layout

| Path | Purpose |
|------|---------|
| `index.php` + `.htaccess` | Front controller (XAMPP-friendly URLs) |
| `src/` | Config, DB, router/App, Phone E.164, SmsGateway client, repositories |
| `bin/setup.php` | Create DB for current `APP_ENV` + apply schema |
| `bin/worker.php` | Queue drain (run via cron / Task Scheduler) |
| `bin/register-worker-task.ps1` | Register the every-minute Windows Task Scheduler job |
| `bin/manage-keys.php` | Create / list / revoke API keys |
| `bin/check-queue.php` | Show recent queue rows |
| `bin/check-message.php` | Query live gateway state for a gateway message ID |
| `database/schema.sql` | Tables: `api_keys`, `sms_messages`, `app_settings` |
| `docs/CONSUMERS.md` | Integration guides: plain PHP, Laravel, React |
| `docs/DEPLOY.md` | Portability runbook: XAMPP → Hostinger checklist + cron snippets |
| `tests/run.php` | Dependency-free test suite (mock gateway) |

## Setup (XAMPP)

1. Copy `.env.example` to `.env` and fill in gateway credentials (`SMS_GATEWAY_URL/USERNAME/PASSWORD` from the Android app).
2. Create the database + tables:

   ```
   C:\xampp\php\php.exe bin\setup.php
   ```

3. Issue a key:

   ```
   C:\xampp\php\php.exe bin\manage-keys.php create --name="HRMIS" --rate=30
   ```

4. Automate the worker (every minute):

   ```
   powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1
   ```

## Consumer integration

Full guides for fresh **plain PHP**, **Laravel**, and **React** projects live in [`docs/CONSUMERS.md`](docs/CONSUMERS.md). Deploy/portability checklist (XAMPP → Hostinger) is in [`docs/DEPLOY.md`](docs/DEPLOY.md).

## API v1

### `POST /api/v1/sms/send`

```bash
curl -X POST "http://localhost/projects/jelite_sms_api/api/v1/sms/send" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"+639171234567\",\"message\":\"Hello from JE Lite SMS API\"}"
```

Response `202` (or `200` when `client_ref` replays an existing message):

```json
{ "id": 1, "status": "queued" }
```

Errors: `400 invalid_json`, `401 unauthorized`, `422 validation_failed` (with `fields`), `429 rate_limited`.

### `GET /api/v1/sms/{id}`

Queue/delivery status: `queued` → `sending` → `sent` | `failed`. Only the owning API key can read a message.

### `GET /api/v1/health`

No auth. Reports database and gateway reachability (no secrets).

## Consumer examples

Laravel:

```php
Http::withToken($key)->post($base.'/api/v1/sms/send', [
    'to' => '+639171234567',
    'message' => 'Hello',
    'client_ref' => 'leave-request-42', // optional idempotency
]);
```

CodeIgniter / plain PHP:

```php
$ch = curl_init($base . '/api/v1/sms/send');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['to' => '+639171234567', 'message' => 'Hello']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_RETURNTRANSFER => true,
]);
```

React: call your own backend only — never embed the Bearer key in browser code.

## Gateway modes (`SMS_GATEWAY_URL` + `SMS_API_PATH`)

| Mode | URL | Path |
|------|-----|------|
| Local phone | `http://PHONE_LAN_IP:8080` | `/message` |
| Private/self-hosted | `https://sms.yourdomain.com` | `/api/3rdparty/v1/messages` |
| Public cloud | `https://api.sms-gate.app` | `/3rdparty/v1/messages` |

Cloud is HTTPS-only (HTTP can 308 and break POST); the client normalizes this automatically.

## Admin UI

Open `http://localhost/projects/jelite_sms_api/admin` and log in with `ADMIN_USER` / `ADMIN_PASSWORD` from `.env`.

- **Settings** — edit gateway/SMS tunables (`SMS_*`, `APP_URL`) without touching `.env`; values are stored in the `app_settings` table and override `.env`. The gateway password is write-only (blank = unchanged).
- **API Keys** — create (plaintext shown once), list, revoke.
- **Messages** — read-only view of the 50 most recent queue rows.
- **Usage** — per-app (API key) counts for a date range: total / sent / failed / queued / sending, plus last used. Default range is the last 7 days.
- **Test** — config probe (database/gateway status) and send-a-test-SMS as a selected consumer key, with optional one-shot worker run. Sends count against that key's rate limit; the response panel shows the same JSON shape consumer apps get.

Admin credentials live only in `.env` (`ADMIN_USER`, `ADMIN_PASSWORD`) and cannot be changed from the UI. Consumer Bearer keys do not grant admin access.

## Tests

```
C:\xampp\php\php.exe tests\run.php
```

Uses the `jelite_sms_api_test` database and a mocked gateway transport — no real SMS is sent from tests.

## Planned next: Phase 6 (Hostinger)

Phase 5.5 deliverables are in place (`docs/CONSUMERS.md`, `docs/DEPLOY.md`, Task Scheduler script). See [`PLAN.md`](PLAN.md) for the deferred Hostinger deploy steps.

## Out of scope

Microsoft Entra / DICT SSO (parked separately) · inbound SMS/reply commands · migrating LOKA off its direct gateway path.
