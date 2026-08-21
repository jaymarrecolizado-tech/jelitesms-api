# Troubleshooting

Quick diagnosis for the errors you'll actually see.

## HTTP responses

| Symptom | Cause | Fix |
|---------|-------|-----|
| `401 unauthorized` | Key missing, malformed, or revoked | Check `Authorization: Bearer <key>` header; create a fresh key in Admin → API Keys |
| `400 invalid_json` | Body isn't valid JSON | Check encoding; send `Content-Type: application/json` |
| `422 validation_failed` with `fields.to` | Phone format unrecognized | Use `09XXXXXXXXX`, `9XXXXXXXXX`, or `+639XXXXXXXXX` |
| `422` with `fields.message` | Empty or over-length message | Max length is in Admin → Settings (`SMS_MAX_MESSAGE_LENGTH`, default 320) |
| `429 rate_limited` | Key exceeded its per-minute limit | Back off and retry; ask the admin to raise the key's rate |
| `200` instead of expected `202` | Duplicate `client_ref` replayed | Expected idempotency — use a fresh ref for genuinely new messages |

## Message lifecycle problems

| Symptom | Cause | Fix |
|---------|-------|-----|
| Stuck at `queued` | Worker not running | Local: run `powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1` (or Admin → Test → "Run worker once"). Server: schedule `bin/worker.php` every minute via cron |
| `failed` with an HTTP error in Admin → Messages | Gateway rejected or unreachable | Admin → Test → *Check configuration*; verify gateway credentials in Admin → Settings |
| `sent` but phone got nothing after minutes | Device offline/no signal, or carrier delay | Ask the admin to check the Android SMS Gateway app is connected; inspect state via `php bin/check-message.php <gateway_message_id>` |
| Never becomes `delivered` | Delivery sync hasn't run yet, or gateway doesn't report it | Sync runs after each worker drain and via `bin/sync-delivery.php`; check `gateway_state` via the status endpoint |

## Debug checklist

1. `GET /api/v1/health` (no auth) — database up? gateway reachable?
2. Admin → **Test** → *Check configuration* — same probe from the UI.
3. Admin → **Messages** — find your message id, read `error`.
4. Admin → **Reports** — filter by your app + status; export CSV if you need
   to attach evidence to a ticket.
5. Still stuck? Note the message id and ask the admin to run
   `php bin/check-message.php <gateway_message_id>`.

Next: **Next steps**.
