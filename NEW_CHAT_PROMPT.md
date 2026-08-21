# New Cursor chat — paste this prompt

Open folder: `C:\xampp\htdocs\Projects\jelite_sms_api`  
Then paste everything below the line into a new **Agent** chat.

---

Build the **JE Lite SMS API** in this workspace using [`PLAN.md`](PLAN.md) as the source of truth.

## Goal

A standalone PHP + MySQL HTTP service that wraps **SMS Gateway for Android** (capcom6 / sms-gate.app) so Laravel, CodeIgniter, React backends, HRMIS, and other apps can send SMS with an API key.

- Project path is already this folder: `C:\xampp\htdocs\Projects\jelite_sms_api`
- Do **not** put this inside LOKA
- Do **not** implement DICT / Microsoft Entra SSO

## Upstream provider (exact contract)

- Docs: https://docs.sms-gate.app — GitHub: https://github.com/capcom6/android-sms-gateway
- Auth to gateway: HTTP Basic (username/password from the Android app)
- POST JSON: `{ "textMessage": { "text": "..." }, "phoneNumbers": ["+639..."] }`
- Modes:
  - Local: base `http://PHONE_IP:8080`, path `/message`
  - Private: base `https://sms.yourdomain.com`, path `/api/3rdparty/v1/messages`
  - Cloud: base `https://api.sms-gate.app`, path `/3rdparty/v1/messages` (HTTPS only)
- Reference to mirror: `C:\xampp\htdocs\Projects\prod-loka-push\public_html\classes\SmsGateway.php` and `C:\xampp\htdocs\Projects\prod-loka-push\devops\sms-gateway\README.md`

## Public API (consumers)

- `POST /api/v1/sms/send` — Bearer API key; body `{ "to", "message", "client_ref?" }` → enqueue; return **202** + id
- `GET /api/v1/sms/{id}` — status
- `GET /api/v1/health` — no secrets
- React must call via its own backend; never put API keys in the frontend

## Architecture requirements

- Separate MySQL databases for **dev / test / prod** — never JSON file storage
- Queue + cron/worker to drain (soft-fail; do not block HTTP on the gateway)
- Store API keys **hashed**; rate limits; E.164 phones with default country `63`; max length ~320
- Env: `SMS_GATEWAY_URL`, `SMS_GATEWAY_USERNAME`, `SMS_GATEWAY_PASSWORD`, `SMS_API_PATH`, `SMS_TIMEOUT_SECONDS`, DB creds, `APP_URL`
- README with curl + Laravel HTTP client + simple CI/PHP example
- Thorough PHP tests for send validation, auth, and queue status
- Keep files small; simple solutions; mock the gateway only in tests — no fake SMS in normal dev/prod
- Python is not required for this service (PHP backend is fine); follow PLAN.md stack

## Out of scope

- Microsoft Entra / DICT SSO
- Changing LOKA to use this API (optional later)
- Inbound SMS / reply commands

## Do now

Implement Phase 1 end-to-end per `PLAN.md`: project scaffold, schema/migrations, send/status/health endpoints, gateway client, queue worker, API key management, `.env.example`, README, and tests.

Only create a git commit if I explicitly ask.
