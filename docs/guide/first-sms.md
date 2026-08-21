# Your first SMS

Send one message with curl, then watch it move through the pipeline. This is
the exact same request your app will make in later chapters.

## Send it

```bash
curl -X POST "http://localhost/projects/jelite_sms_api/api/v1/sms/send" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"09171234567\",\"message\":\"Hello from JE Lite SMS API\"}"
```

Response (`202`):

```json
{ "id": 42, "status": "queued" }
```

Phone formats all work: `09171234567`, `9171234567`, `+639171234567` —
everything normalizes to E.164.

## Watch it travel

| Where | What you see |
|-------|--------------|
| `GET /api/v1/sms/42` (with your key) | `queued → sending → sent → delivered` |
| Admin → **Messages** | The queue row, attempts, errors |
| Admin → **Reports** | Delivery outcome: delivered/failed, gateway state, CSV export |
| Admin → **Usage** | Your app's per-minute/per-day counts |

Status polling:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
  http://localhost/projects/jelite_sms_api/api/v1/sms/42
```

```json
{
  "id": 42,
  "status": "delivered",
  "gateway_state": "Delivered",
  "delivered_at": "2026-08-21 10:03:22",
  ...
}
```

## Make it idempotent from day one

Pass a `client_ref` — your own unique reference for the business event:

```bash
curl -X POST ".../api/v1/sms/send" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"to\":\"09171234567\",\"message\":\"Your OTP is 123456\",\"client_ref\":\"otp-9912\"}"
```

Replaying the same `client_ref` returns `200` with the original id instead of
sending twice. Use patterns like `leave-{id}`, `otp-{user}-{ts}`.

## Verify in the playground

Admin → **Test** lets you re-send as your key and probe config without
writing code. Handy when debugging.

Next: pick your stack — **Plain PHP**, **Laravel**, **CodeIgniter**,
**React + Laravel BFF**, or **React + Node BFF**.
