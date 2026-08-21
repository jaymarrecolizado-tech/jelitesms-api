# JE Lite SMS API — Implementation Plan

**Project path:** `C:\xampp\htdocs\Projects\jelite_sms_api`  
**Purpose:** Standalone HTTP SMS API wrapping Android SMS Gateway (capcom6 / sms-gate.app) for Laravel, CodeIgniter, React backends, LOKA consumers, HRMIS, and other DICT apps.  
**Out of scope for this project:** Microsoft Entra / DICT SSO (separate plan later).

> **Status: Phase 1–5.9 COMPLETE** (API, admin incl. Docs tutorial + Reports, worker, delivery sync).  
> **Next for coding agents: nothing mandatory** — see the Recommended backlog; only act if the user asks.  
> **Phase 6 Hostinger deploy: SKIPPED.** Ops deploy notes live in [`docs/ops/DEPLOY.md`](docs/ops/DEPLOY.md) (owner-only — **not** shown in Admin → Docs).

---

## For the next coding agent (read this first)

**Do not** put Hostinger/ops deploy runbooks in Admin → Docs (consumers must not see them).  
**Do not** rewrite the whole API. **Do not** commit Laravel `vendor/` or `node_modules`.

**Implement next: nothing** — Phase 5.9 (Tutorial Docs) is complete. Only act on the Recommended backlog or if the user un-skips Phase 6.

| Already on disk | Notes |
|-----------------|--------|
| [`docs/CONSUMERS.md`](docs/CONSUMERS.md) | Current Admin → Docs content (reference-style; to be replaced by guide pages) |
| [`docs/ops/DEPLOY.md`](docs/ops/DEPLOY.md) | **Ops only** (you / Phase 6) — never render in Admin Docs |
| Admin Docs | Consumer guide only (deploy tab removed) |
| Admin | Settings, Keys, Messages, Usage, Reports, Test, Docs |

**Rules:** Never put API keys in React client env. Allowlist docs paths only. Prefer small PHP files; mock gateway only in tests.

---

## Locked decisions

| Decision | Choice |
|----------|--------|
| Hosting | This folder — standalone PHP + MySQL on XAMPP, **not** inside LOKA |
| Provider | [SMS Gateway for Android](https://github.com/capcom6/android-sms-gateway) / [sms-gate.app](https://sms-gate.app) |
| Consumer auth | API keys: `Authorization: Bearer <key>` |
| Frontend | React/SPA must **never** call this API with a secret; only server-side |
| Send model | Enqueue + cron/worker (soft-fail; do not block HTTP on gateway) |
| LOKA | Keeps its current direct `SmsGateway` path unless later migrated |

```mermaid
flowchart LR
  Laravel[Laravel_CI_Node] -->|Bearer_API_key| SmsApi[jelite_sms_api]
  ReactBE[React_BFF] -->|Bearer_API_key| SmsApi
  SmsApi -->|enqueue| Queue[sms_messages]
  Cron[cron_worker] -->|drain| Queue
  Queue --> Gw[sms_gate_Android]
  Gw --> Sim[Phone_SIM]
```

---

## Upstream provider (exact contract)

Mirror LOKA: `C:\xampp\htdocs\Projects\prod-loka-push\public_html\classes\SmsGateway.php`  
Ops notes: `C:\xampp\htdocs\Projects\prod-loka-push\devops\sms-gateway\README.md`  
Docs: https://docs.sms-gate.app

### Modes

| Mode | Gateway base URL | API path |
|------|------------------|----------|
| Local phone | `http://PHONE_LAN_IP:8080` | `/message` |
| Private / self-hosted | `https://sms.yourdomain.com` | `/api/3rdparty/v1/messages` |
| Public cloud | `https://api.sms-gate.app` | `/3rdparty/v1/messages` |

- Cloud: **HTTPS only** (HTTP can 308 and break POST).
- Auth to gateway: **HTTP Basic** (username/password from Android app).
- Never expose gateway password to API consumers.

### Upstream POST body

```json
{
  "textMessage": { "text": "Your message" },
  "phoneNumbers": ["+639171234567"]
}
```

---

## Public API (v1) — ✅ all live and smoke-tested

| Method | Path | Auth | Purpose | Status |
|--------|------|------|---------|--------|
| `POST` | `/api/v1/sms/send` | Bearer | Enqueue SMS | ✅ |
| `GET` | `/api/v1/sms/{id}` | Bearer | Queue/delivery status (key-isolated) | ✅ |
| `GET` | `/api/v1/health` | none | Liveness + gateway reachability (no secrets) | ✅ |

Extras shipped beyond the original spec: idempotent `client_ref` replay (`200` + same id),
per-key rate limiting (`429`), key isolation on status reads, `400 invalid_json` / `422 validation_failed`
JSON errors.

### POST `/api/v1/sms/send`

```json
{
  "to": "+639171234567",
  "message": "Your text",
  "client_ref": "optional-idempotency-or-app-ref"
}
```

**Response `202`:**

```json
{ "id": "...", "status": "queued" }
```

---

## Build phases

### Phase 1 — Scaffold — ✅ DONE

1. ✅ PHP router / front controller (`index.php` + `.htaccess`, XAMPP-friendly URL; base path derived from filesystem, `Authorization` header preserved through mod_rewrite).
2. ✅ `.env` + `.env.example` (real secrets gitignored).
3. ✅ Separate MySQL databases for **dev / test / prod** via `APP_ENV` (`jelite_sms_api_dev/_test/_prod`); dev + test created.
4. ✅ Config: `APP_URL`, DB creds, gateway URL/user/pass/path, timeouts, default country `63`, max message length `320` (+ `SMS_MAX_ATTEMPTS`, `WORKER_BATCH_SIZE`).

### Phase 2 — Data model — ✅ DONE

Tables (in `database/schema.sql`, applied by `bin/setup.php`):

- ✅ `api_keys` — name, SHA-256 key hash, prefix for display, active, rate limits, created/revoked
- ✅ `sms_messages` — id, api_key_id, to_e164, body, client_ref (unique per key → idempotency), status (`queued` / `sending` / `sent` / `failed`), gateway_message_id, error, attempts, timestamps

### Phase 3 — Core services — ✅ DONE

1. ✅ Phone normalize to E.164 (default country `63`) — `src/Phone.php`
2. ✅ `SmsGateway` client ported from LOKA (`src/SmsGateway.php`: Basic auth, JSON payload, short timeouts, no follow redirects, cloud-path forcing, URL normalization; injectable transport for tests)
3. ✅ Queue enqueue on `POST /send`; worker drains queue (`bin/worker.php`, soft-fail with retry until `SMS_MAX_ATTEMPTS`)
4. ✅ API key create/revoke/list CLI (`bin/manage-keys.php`); **hashed** keys only
5. ✅ Rate limiting per key; validation errors as clear JSON

### Phase 4 — Docs and tests — mostly done

1. ✅ `README.md` — setup, env, curl, Laravel HTTP client, CodeIgniter/PHP example, React-via-backend note
2. ⬜ Optional `openapi.yaml`
3. ✅ Tests (`tests/run.php`, dependency-free runner): auth, validation, enqueue/status/idempotency/rate-limit, gateway contract, worker drain — **63/63 passing**; gateway mocked only in tests

### Phase 5 — Admin UI (config + keys) — ✅ DONE (incl. Test/Playground page)

Browser interface so operators can change gateway/SMS settings and manage API keys without hand-editing `.env` for every tweak. Same PHP project + HTML/CSS/JS (no separate React admin SPA).

#### Locked design choices

| Choice | Decision |
|--------|----------|
| URLs | `/admin` (login), `/admin/settings`, `/admin/keys`, `/admin/messages`, `/admin/usage`, `/admin/test` |
| Auth | Session cookie; bootstrap only in `.env`: `ADMIN_USER`, `ADMIN_PASSWORD` |
| Storage | MySQL `app_settings` (not JSON files) |
| UI can change | Gateway + SMS tunables + API keys |
| Stays in `.env` only | `APP_ENV`, `DB_*`, `ADMIN_*` (not editable in UI) |

#### Local URL

`http://localhost/projects/jelite_sms_api/admin`

#### Settings page (editable) — ✅

- `SMS_GATEWAY_URL`, `SMS_GATEWAY_USERNAME`, `SMS_GATEWAY_PASSWORD` (masked), `SMS_API_PATH`
- `SMS_TIMEOUT_SECONDS`, `SMS_DEFAULT_COUNTRY_CODE`, `SMS_MAX_MESSAGE_LENGTH`
- `SMS_MAX_ATTEMPTS`, `WORKER_BATCH_SIZE`, `APP_URL`

#### API Keys page — ✅

- Create / list / revoke; plaintext key shown **once** on create

#### Messages page (read-only) — ✅

- Recent queue rows: id, to, status, attempts, error, timestamps

#### Usage page — ✅ DONE (`/admin/usage`)

Monitor **which consumer apps** use the API (each named API key = one app).

- Date filter (`from` / `to`, default last 7 days, max 366-day window)
- Per-key table: name, prefix, active/revoked, **total / sent / failed / queued / sending**, **last used**
- Summary totals for the selected range
- Data from `sms_messages` joined to `api_keys` (no extra logging table)

#### Test / Playground page — ✅ DONE (`/admin/test`)

Operator page to **verify config** and **send a test SMS the same way consumer apps do** (Laravel, CI, React backends, other PHP), without leaving the admin UI or using curl.

```mermaid
flowchart LR
  AdminTest[Admin_Test_page]
  Health[Config_probe]
  SendAs[Send_as_API_key]
  ApiPath[Same_POST_send_path]
  Queue[(sms_messages)]
  Worker[Optional_run_worker]

  AdminTest --> Health
  AdminTest --> SendAs
  SendAs --> ApiPath
  ApiPath --> Queue
  AdminTest --> Worker
  Worker --> Queue
```

**A. Test config (no real SMS)**

- Button: **Check configuration**
- Runs the same checks as `GET /api/v1/health` (and related gateway probe): database up/down, gateway configured yes/no, gateway reachable/unreachable
- Shows clear pass/fail in the UI (no secrets displayed)
- Optional: show effective (resolved) non-secret settings: gateway URL, API path, country code, max length, timeouts — so you can confirm DB overrides vs `.env`

**B. Send test SMS (as a consumer app)**

- Purpose: simulate what happens when another app calls `POST /api/v1/sms/send` with a Bearer key
- Form fields:
  - **API key** — dropdown of active keys (by name / id / prefix); send is attributed to that consumer key (rate limit + ownership match production)
  - **to** — phone (E.164 or local)
  - **message** — text
  - **client_ref** — optional idempotency ref
  - **Run worker once after enqueue** — checkbox (local/Hostinger debugging so status can move past `queued` without waiting for cron)
- Server uses the **same validation + enqueue path** as the public API (`App` send handler): same `202`/`200`/`422`/`429` JSON shape consumers get
- After submit: show HTTP-style status + JSON body (`id`, `status`), link to **Messages**, and optional status refresh for that id
- Does **not** require pasting the plaintext Bearer key (admin selects key by id; plaintext remains unrecoverable from DB)

**C. Security / rules for Test page**

- Still requires admin login + CSRF
- Consumer Bearer keys cannot open `/admin/test`
- Test sends count against the selected key’s rate limit (realistic)
- Warn in UI: this can send a **real SMS** if gateway + worker are configured
- React/SPA still never calls this page

#### Config resolution order — ✅

1. Row in `app_settings` (if present and non-empty)  
2. Else `.env` / process env  
3. Else code default  

#### Schema — ✅ `app_settings` in `database/schema.sql`

#### Security rules — ✅ for existing pages; apply to Test page too

- Admin routes require login; consumer Bearer keys do **not** grant admin
- Never show full gateway password after save
- CSRF on admin POSTs
- React/SPA must never call `/admin` or hold SMS API / gateway secrets

#### Implementation touch list for Test page — ✅ all done

- ✅ `AdminApp.php` / `AdminViews.php`: routes `GET|POST /admin/test` (+ `/admin/test/probe`, `/admin/test/send`), nav link **Test**
- ✅ Reuse `App` health + send logic (`App::probe()`, `App::sendRaw()` — no duplicated validation)
- ✅ One-shot worker drain from admin (shared `Worker::drain()` with `bin/worker.php`)
- ✅ Tests in `tests/AdminTest.php`: config probe, send-as-key enqueue, auth gate, CSRF, rate-limit attribution
- ✅ `README.md` — documents `/admin/test`

#### Build order (remaining) — ✅ complete

---

### Phase 5.5 — Local-first ops + docs (Hostinger-ready later) — ✅ DONE

Still testing on **XAMPP localhost**. Do **not** upload to Hostinger in this phase. Keep one codebase portable: local today → env + cron swap on VPS later.

```mermaid
flowchart LR
  Local[XAMPP_now]
  Docs[CONSUMERS_plus_DEPLOY]
  Later[Hostinger_Phase6]

  Local -->|Task_Scheduler_worker| Docs
  Docs -->|same_repo_env_swap| Later
```

#### Locked scope

| Do now (Phase 5.5) | Defer |
|--------------------|--------|
| Automate worker on **XAMPP** (Task Scheduler) | Live Hostinger upload/deploy (Phase 6) |
| Portable schedule docs + Hostinger cron **snippet** for later | Delivery-state sync, OpenAPI, alerts |
| Document API usage for fresh **PHP**, **Laravel**, **React** projects | Wiring real Hostinger apps in their repos |

#### 1. Worker automation (local, portable) — ✅ DONE

Verified live: task "JE Lite SMS Worker" registered (result 0), enqueued message auto-sent by the schedule within one minute, no manual step.

Without a schedule, messages stay `queued` unless Admin → Test “run worker” or CLI is used.

- Document Windows Task Scheduler every minute, e.g.:

```
C:\xampp\php\php.exe C:\xampp\htdocs\Projects\jelite_sms_api\bin\worker.php
```

- Add `bin/register-worker-task.ps1` — registers the task using paths derived from the project folder (re-run after moving the folder).
- Same docs section includes **Hostinger cron** one-liner for Phase 6 (copy-paste later):

```
* * * * * php /path/to/jelite_sms_api/bin/worker.php >> /path/to/jelite_sms_api/worker.log 2>&1
```

#### 2. Deploy portability runbook — ✅ DONE (ops only: `docs/ops/DEPLOY.md`)

Owner/ops checklist for later Hostinger upload (env swap, cron, cloud gateway). **Not** part of consumer Admin Docs — do not surface that content to integrators.

#### 3. Consumer documentation — ✅ DONE (`docs/CONSUMERS.md`)

Full usage guides for fresh projects; link from `README.md`. Shared contract:

- Base URL (local now; VPS URL later)
- `Authorization: Bearer <key>` — **one named key per app** (Admin → API Keys)
- `POST /api/v1/sms/send` → `{ to, message, client_ref? }` → `202` + `{ id, status }`
- `GET /api/v1/sms/{id}` for status
- Never put the key in browser / `NEXT_PUBLIC_*` / Vite client env

**Plain PHP:** config `SMS_API_URL` + `SMS_API_KEY`; curl send + status + `401`/`422`/`429` handling.

**Laravel:** `.env` + optional `config/services.php`; `Http::withToken(...)->post(...)`; call from controller/job/service only.

**React:** browser never calls this API. Pattern: React UI → your Laravel/Node/PHP backend → jelite_sms_api with server-side key. Document anti-pattern of SPA `fetch` with Bearer.

Local examples use: `http://localhost/projects/jelite_sms_api`  
Note in each section: swap base URL when Phase 6 goes live.

#### Phase 5.5 done when — ✅ all met

1. ✅ Worker runs every minute on XAMPP via Task Scheduler (script + docs) — verified live end-to-end.
2. ✅ `docs/CONSUMERS.md` covers PHP, Laravel, React.
3. ✅ Ops deploy runbook available at `docs/ops/DEPLOY.md` (not in Admin Docs)
4. ✅ `README.md` / this plan point to those docs.

---

### Phase 5.6 — Consumer docs coverage — ✅ DONE

**Goal:** Make [`docs/CONSUMERS.md`](docs/CONSUMERS.md) the complete integration guide for fresh projects. Baseline file already exists — **expand and restructure**; do not throw it away. Phase 6 deploy remains skipped.

#### Current coverage (baseline — keep and improve)

| Stack | Already in CONSUMERS.md | Gaps to fill |
|-------|-------------------------|--------------|
| Shared contract | Auth, send/status codes, phone formats, no-browser-keys | Quick-start checklist (create key → `.env` → first send → Usage) |
| Plain PHP | `.env`, `smsSend` curl helper, short status curl | Full `smsStatus()` helper; curl one-liners; health check |
| Laravel | `config/services.php`, `SmsService::send`, controller snippet | `status($id)` method; queued Job example; exception mapping for 401/422/429 |
| React | SPA→backend→SMS API; Node/Express sample; anti-pattern | **Laravel BFF** example (common for DICT apps); what the React UI may safely show |
| Extra | Testing via Admin → Test | Troubleshooting table; CodeIgniter short section (project purpose lists CI) |

#### Agent implementation checklist (Phase 5.6)

1. **Quick start (top of CONSUMERS.md)** — numbered steps:
   1. Open Admin → API Keys → create named key (e.g. `MyLaravelApp`)
   2. Copy plaintext key once into the consumer app `.env`
   3. Set `SMS_API_URL=http://localhost/projects/jelite_sms_api`
   4. Send first SMS (link to stack section)
   5. Confirm in Admin → Messages / Usage; optional Admin → Test
2. **Expand Plain PHP** — complete status helper + copy-paste `curl.exe` send/status/health examples for Windows.
3. **Expand Laravel** — `SmsService` with `send` + `status`; map HTTP codes; example Job; remind not to call from Blade with embedded secrets.
4. **Expand React** — keep Node sample; **add Laravel-as-BFF** route example the React app calls; clarify UI only sees `{ ok: true }` / business errors, never the gateway password or SMS API key.
5. **Add CodeIgniter (short)** — `.env` + simple curl/`CURLRequest` send snippet (aligned with project purpose).
6. **Add Troubleshooting** — table for 401 / 422 / 429 / stuck `queued` (worker not running → point to `bin/register-worker-task.ps1`) / wrong phone format.
7. **README** — keep prominent link to `docs/CONSUMERS.md`; ensure Layout table lists it.
8. **Mark Phase 5.6 done** in this `PLAN.md` when the checklist above is met.

#### Phase 5.6 done when

- [x] Quick-start checklist present
- [x] PHP / Laravel / React sections are copy-paste complete for a fresh project (incl. status where relevant)
- [x] React includes a Laravel BFF path (not only Node)
- [x] Short CodeIgniter section present
- [x] Troubleshooting section present
- [x] README still points at `docs/CONSUMERS.md`
- [x] This plan's Phase 5.6 status flipped to DONE

---

### Phase 5.7 — Admin Docs page — ✅ DONE

**Goal:** Dedicated admin page so operators can read the consumer (and deploy) guides in the browser without opening Markdown in the IDE.

**URL:** `http://localhost/projects/jelite_sms_api/admin/docs`  
**Auth:** logged-in admin session only (same as Settings / Keys / …).

#### Locked design

| Choice | Decision |
|--------|----------|
| Route | `GET /admin/docs` with optional `?doc=consumers` (default) or `?doc=deploy` |
| Source | Read from disk: [`docs/CONSUMERS.md`](docs/CONSUMERS.md) only (deploy runbook removed from Admin Docs; see `docs/ops/DEPLOY.md`) |
| Rendering | Small in-repo Markdown→HTML helper (no Composer). Support headings, fenced code, tables, lists, bold, inline code, links. Escape HTML by default |
| Nav | Add **Docs** to admin nav in [`src/AdminViews.php`](src/AdminViews.php) |
| Content | Do **not** duplicate the guide into PHP strings — always render the Markdown files |

```mermaid
flowchart LR
  Nav[Admin_Docs_nav]
  Route[GET_admin_docs]
  Allow[Allowlisted_docs_read]
  Md[Markdown_to_HTML]
  View[docsPage_tabs]

  Nav --> Route
  Route --> Allow
  Allow --> Md
  Md --> View
```

#### Agent implementation checklist

1. Add `src/Markdown.php` (or `DocsRenderer.php`) for the Markdown subset above.
2. `AdminApp`: `GET /admin/docs` — resolve allowlisted doc, render HTML, pass to view; invalid `doc` falls back to consumers.
3. `AdminViews`: nav link **Docs**; consumer guide only (no Deploy tab).

#### Phase 5.7 done when

- [x] `/admin/docs` works for logged-in admin
- [x] Consumer guide visible (from `docs/CONSUMERS.md`)
- [x] Editing the Markdown file updates the page on refresh (rendered from disk each request)
- [x] Tests pass; README + this plan updated

**Later change:** Deploy tab **removed** from Admin Docs. Ops runbook moved to [`docs/ops/DEPLOY.md`](docs/ops/DEPLOY.md) (not for consumers).

---

### Phase 5.8 — Delivery Reports — ✅ DONE

**Goal:** Real **delivery reports** — sync phone-level delivery state from sms-gate, show aggregates in Admin, and expose richer status to consumer apps.

**Why:** Usage/Messages today mostly reflect queue acceptance. `sent` ≈ gateway accepted the job, not necessarily **Delivered** to the handset. Manual check exists via [`bin/check-message.php`](bin/check-message.php); Phase 5.8 automates that and reports on it.

```mermaid
flowchart LR
  Worker[Send_worker]
  Gw[sms_gate_cloud]
  Phone[Phone_SIM]
  Sync[Delivery_sync_job]
  DB[(sms_messages)]
  Reports[Admin_Reports]
  Api[GET_sms_id]

  Worker -->|POST_message| Gw
  Gw --> Phone
  Sync -->|poll_by_gateway_message_id| Gw
  Sync --> DB
  DB --> Reports
  DB --> Api
```

#### Locked design

| Piece | Choice |
|-------|--------|
| Status model | Keep `queued` / `sending` / `failed`; `sent` = handed to gateway; add **`delivered`** when sms-gate reports Delivered |
| Extra columns | `delivered_at` DATETIME NULL; `gateway_state` VARCHAR (raw gateway state string) on `sms_messages` |
| Sync runner | `bin/sync-delivery.php` (and/or hook from worker after send) — schedule same cadence as worker (Task Scheduler / cron later) |
| Gateway lookup | Reuse/extend [`SmsGateway`](src/SmsGateway.php) + patterns from `bin/check-message.php`; injectable transport for tests |
| Admin page | `GET /admin/reports` — filters: from/to, API key, status; summary totals; per-app breakdown; optional message drill-down; **CSV export** |
| Nav | Add **Reports** in admin nav |
| Consumer API | `GET /api/v1/sms/{id}` includes `status` (incl. `delivered`), `gateway_state`, `delivered_at` when set |
| Docs | Update CONSUMERS.md status section + Admin Docs will pick it up from disk |

Without sync first, Reports would only duplicate **Usage**.

#### Agent implementation checklist (Phase 5.8) — ✅ all done

1. ✅ **Schema** — `delivered` enum value, `delivered_at`, `gateway_state` in `database/schema.sql`; idempotent migration via `Database::migrate()` (used by `bin/setup.php` and tests).
2. ✅ **SmsGateway** — `getState(string $gatewayMessageId)` (GET `{apiPath}/{id}`, Basic auth, injectable transport).
3. ✅ **Sync** — `Worker::syncDeliveries()` + `bin/sync-delivery.php`; also runs after each `bin/worker.php` drain. Maps Delivered → `delivered`, Failed/Cancelled → terminal `failed` (+reason), others recorded raw in `gateway_state`. Window: `SMS_DELIVERY_SYNC_DAYS` (default 7), batch: `WORKER_BATCH_SIZE`.
4. ✅ **API** — status response includes `gateway_state` and `delivered_at`; key isolation unchanged.
5. ✅ **Admin Reports** — `GET /admin/reports` (from/to, app, status filters; summary; per-app breakdown; 200-row drill-down) + `GET /admin/reports/export` CSV.
6. ✅ **Schedule docs** — cron/Task Scheduler note for `bin/sync-delivery.php` in `docs/ops/DEPLOY.md`.
7. ✅ **Tests** — `tests/DeliverySyncTest.php` (state mapping, window, limit, API fields) + `tests/ReportsTest.php` (auth, aggregates, filters, CSV) — **193/193 passing**.
8. ✅ **Marked DONE** here + README Admin bullet for Reports.

#### Phase 5.8 done when — ✅ all met

- [x] Sync updates rows to `delivered` / `failed` from gateway states
- [x] `/admin/reports` shows delivery totals by date/app/status (+ CSV)
- [x] `GET /api/v1/sms/{id}` exposes delivery fields
- [x] CONSUMERS.md documents new statuses/fields
- [x] Tests pass; PLAN/README updated

#### Out of scope for 5.8 (later backlog)

- Webhooks on delivered/failed  
- Messages filters / requeue UI  
- Bulk send, scheduled send  
- OpenAPI  
- Phase 6 Hostinger  

---

### Recommended backlog (after 5.8 — not started)

Prioritized ideas for later phases (do not implement unless user asks):

| Priority | Feature | Notes |
|----------|---------|--------|
| High | Messages filters | Filter by key, status, date |
| High | Queue backlog warning | Banner/alert if many stuck `queued` |
| Medium | Usage/Reports CSV (Usage already partial via Reports CSV in 5.8) | |
| Medium | Consumer webhooks | Per-key callback URL on terminal status |
| Medium | Bulk / multi-recipient send | Gateway supports phone arrays |
| Medium | Scheduled send (`send_at`) | Worker claims due rows only |
| Lower | Key scopes / admin password UI / audit log / IP allowlist for `/admin` | |
| Optional | `openapi.yaml` | |
| Deferred | Phase 6 Hostinger, inbound SMS, DICT SSO, LOKA migration | |

---

### Phase 5.9 — Tutorial Docs (side nav + from-scratch guides) — ✅ DONE

**Goal:** Replace the basic single-page Docs dump with a **start-to-finish tutorial** for consumers: ordered chapters, **left side navigation**, and sample projects per framework (plain PHP / Laravel / CodeIgniter / React).

**Ops deploy content stays out of Admin Docs.** Owner-only file: [`docs/ops/DEPLOY.md`](docs/ops/DEPLOY.md). Never expose Hostinger/upload checklists in consumer Docs.

#### Locked design

| Choice | Decision |
|--------|----------|
| Structure | `docs/guide/*.md` multi-page chapters |
| Admin UI | Sticky **left side nav** + main content |
| Routing | `GET /admin/docs?page=welcome` (allowlisted ids only) |
| Samples | `examples/plain-php/` runnable; Laravel/CI/React drop-ins under `examples/` (no vendor/node_modules) |
| CONSUMERS.md | Index or redirect to guide welcome |
| Deploy | **Not** in Admin Docs — `docs/ops/` only |

#### Guide side-nav chapters

1. Welcome / prerequisites  
2. Create API key  
3. First SMS (curl) + verify in Messages/Usage/Test/Reports  
4. Plain PHP from scratch (`examples/plain-php`)  
5. Laravel from scratch  
6. CodeIgniter from scratch  
7. React + Laravel BFF  
8. React + Node BFF (short)  
9. Troubleshooting  
10. Next steps (no Hostinger checklist)

#### Agent checklist — ✅ all done

1. ✅ Page registry (`AdminApp::GUIDE`) + sticky side nav UI + allowlist `docs/guide/*` (unknown/legacy/traversal ids fall back to welcome)
2. ✅ 10 chapters written + examples shipped: `examples/plain-php` (runnable), `laravel/`, `codeigniter/`, `react-node-bff/` (runnable) drop-ins
3. ✅ Tests: nav, page switching, allowlist fallback, **assert Admin Docs never contains ops/DEPLOY content** (204/204 passing)
4. ✅ Marked 5.9 DONE in PLAN/README

#### Done when — ✅ all met

- [x] Side nav + multi-page tutorials in Admin → Docs
- [x] PHP / Laravel / CI / React from-scratch paths
- [x] `examples/plain-php` works locally
- [x] Ops deploy runbook **not** visible in Admin Docs
- [x] Tests + PLAN/README updated

---

### Phase 6 — Hostinger deploy — SKIPPED (for now)

Do **not** implement until the user explicitly asks. Owner runbook: [`docs/ops/DEPLOY.md`](docs/ops/DEPLOY.md) (**not** Admin Docs).

When un-skipped: same VPS as consumer apps, cloud gateway only, cron for `bin/worker.php` + `bin/sync-delivery.php`, one key per app.

---

### Remaining (priority order)

**Phase 5.5 — COMPLETE** (baseline docs + local worker schedule).

**Phase 5.6 — COMPLETE** (`docs/CONSUMERS.md` expanded: quick start, full PHP helpers,
Laravel send/status/job, CodeIgniter, React Node + Laravel BFF patterns, troubleshooting table).

**Phase 5.7 — COMPLETE** (Admin Docs for consumers; deploy tab removed — ops runbook at `docs/ops/DEPLOY.md`).

**Phase 5.8 — COMPLETE** (delivery-state sync via `Worker::syncDeliveries()` +
`bin/sync-delivery.php`; `delivered` status, `delivered_at`, `gateway_state` columns with
idempotent migration; Admin **Reports** page `/admin/reports` with filters + CSV export;
`GET /api/v1/sms/{id}` exposes `gateway_state`/`delivered_at`; tests passing).

**Phase 5.9 — COMPLETE** (Admin → Docs redesigned as a 10-chapter tutorial with sticky
left side nav; `docs/guide/*` allowlisted pages; from-scratch guides for plain PHP /
Laravel / CodeIgniter / React BFFs; runnable samples in `examples/plain-php` and
`examples/react-node-bff`; ops deploy content excluded — `docs/ops/DEPLOY.md` owner-only;
204/204 tests passing).

**Phase 6 — SKIPPED:**

- Live Hostinger deploy (user deferred); see `docs/ops/DEPLOY.md` when ready

**Later / optional (only if user asks):**

- See **Recommended backlog** above (filters, webhooks, bulk, OpenAPI, …)

Diagnostics helpers: `bin/check-queue.php`, `bin/check-message.php <gateway_message_id>`.

---

## Suggested env vars — core + ADMIN_* supported

```
APP_URL=http://localhost/projects/jelite_sms_api
APP_ENV=dev

DB_HOST=127.0.0.1
DB_USER=root
DB_PASS=

SMS_GATEWAY_URL=https://api.sms-gate.app
SMS_GATEWAY_USERNAME=
SMS_GATEWAY_PASSWORD=
SMS_API_PATH=/3rdparty/v1/messages
SMS_TIMEOUT_SECONDS=15
SMS_DEFAULT_COUNTRY_CODE=63
SMS_MAX_MESSAGE_LENGTH=320
SMS_MAX_ATTEMPTS=3
WORKER_BATCH_SIZE=20
SMS_DELIVERY_SYNC_DAYS=7

# Phase 5 — admin bootstrap (not editable in UI)
ADMIN_USER=admin
ADMIN_PASSWORD=
```

DB name is derived as `jelite_sms_api_{APP_ENV}` (no `DB_NAME` required).  
Local phone test: `SMS_GATEWAY_URL=http://PHONE_IP:8080` and `SMS_API_PATH=/message`.

---

## Consumer examples

Short examples in `README.md`. **Full fresh-project guides:** [`docs/CONSUMERS.md`](docs/CONSUMERS.md).

```bash
curl -X POST "http://localhost/projects/jelite_sms_api/api/v1/sms/send" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"+639171234567\",\"message\":\"Hello from JE Lite SMS API\"}"
```

Laravel: `Http::withToken($key)->post($base.'/api/v1/sms/send', [...])`  
React: call your Laravel/CI/Node backend only — never embed the Bearer key in the browser.

---

## After Phase 1 ships

- Prod API keys per consumer when Phase 6 is un-skipped; local keys anytime via Admin → API Keys.
- Keep LOKA on its existing gateway until you choose to migrate (untouched).
- SSO / Sign in with DICT stays on the parked plan outside this folder.

---

## How to start in Cursor (next agent)

1. **File → Open Folder** → `C:\xampp\htdocs\Projects\jelite_sms_api`
2. Read **"For the next coding agent"** in this file — all planned phases (1–5.9) are complete
3. Only act if the user asks for a Recommended-backlog item or un-skips **Phase 6** (Hostinger deploy — follow `docs/ops/DEPLOY.md`)
4. Only create a git commit if the user explicitly asks
