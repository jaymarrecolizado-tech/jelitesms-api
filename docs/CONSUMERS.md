# Consumer Integration Guide

How to send SMS from your app (plain PHP, Laravel, or React-backed projects) via the JE Lite SMS API.

## Shared contract

- **Base URL (local):** `http://localhost/projects/jelite_sms_api`
  - Swap to the production URL (e.g. `https://sms-api.yourdomain.com`) when Phase 6 goes live — nothing else changes.
- **Auth:** `Authorization: Bearer <key>` — get one named key per app from **Admin → API Keys** (`/admin`). The plaintext key is shown once at creation; store it in your app's server-side `.env`.
- **Send:** `POST /api/v1/sms/send` with JSON `{ "to", "message", "client_ref?" }`

| Response | Meaning |
|----------|---------|
| `202` `{ "id": n, "status": "queued" }` | Accepted, queued for delivery |
| `200` `{ "id": n, ... }` | Same `client_ref` seen before (idempotent replay) — no duplicate SMS |
| `400` | Body is not valid JSON |
| `401` | Missing/invalid API key |
| `422` | Validation failed (`fields` object says which) |
| `429` | Key's rate limit (per minute) exceeded |

- **Status:** `GET /api/v1/sms/{id}` → `{ id, to, status: queued|sending|sent|failed, client_ref, attempts, error, created_at, sent_at }`. Only the key that created the message can read it.
- Phones accept E.164 (`+639171234567`), local (`09171234567`), or bare (`9171234567`) formats.
- Messages are delivered asynchronously by the queue worker (runs every minute). Delivery is usually near-instant but not guaranteed within any specific time.
- **Never put the API key in browser code**, `NEXT_PUBLIC_*` vars, Vite client env, or mobile app bundles. Only server-side code calls this API.

---

## Plain PHP

`.env` (or your config):

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jl_paste_your_key_here
```

Send + status helper:

```php
function smsConfig(string $key): string
{
    static $cfg = null;
    $cfg ??= parse_ini_file(__DIR__ . '/.env');
    return $cfg[$key] ?? '';
}

function smsSend(string $to, string $message, ?string $clientRef = null): array
{
    $payload = json_encode(array_filter([
        'to' => $to,
        'message' => $message,
        'client_ref' => $clientRef,
    ], fn ($v) => $v !== null));

    $ch = curl_init(rtrim(smsConfig('SMS_API_URL'), '/') . '/api/v1/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . smsConfig('SMS_API_KEY'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $status, 'body' => $body ?? null];
}

// Usage:
$result = smsSend('09171234567', 'Your leave request was approved.', 'leave-42');
match (true) {
    $result['http'] === 202 => 'queued',
    $result['http'] === 200 => 'duplicate request (already sent)',
    $result['http'] === 401 => log_error('Bad SMS API key'),
    $result['http'] === 422 => log_error('Invalid input', $result['body']['fields'] ?? []),
    $result['http'] === 429 => retry_later(),          // rate limited
    default       => log_error('SMS API unavailable'),
};
```

Status check:

```php
$ch = curl_init(smsConfig('SMS_API_URL') . '/api/v1/sms/' . $id);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . smsConfig('SMS_API_KEY')],
]);
$status = json_decode((string) curl_exec($ch), true); // status: queued|sending|sent|failed
```

---

## Laravel

`.env`:

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jl_paste_your_key_here
```

`config/services.php`:

```php
'sms' => [
    'url' => env('SMS_API_URL'),
    'key' => env('SMS_API_KEY'),
],
```

App-level service (e.g. `app/Services/SmsService.php`) — call this from controllers, jobs, or notifications only:

```php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class SmsService
{
    public function send(string $to, string $message, ?string $clientRef = null): array
    {
        $response = Http::withToken(config('services.sms.key'))
            ->timeout(15)
            ->post(rtrim(config('services.sms.url'), '/') . '/api/v1/sms/send', array_filter([
                'to' => $to,
                'message' => $message,
                'client_ref' => $clientRef,
            ]));

        // 202 = queued, 200 = idempotent replay, 401/422/429 = see docs
        if ($response->status() === 429) {
            throw new \RuntimeException('SMS rate limited — retry shortly');
        }

        return $response->json() ?? [];
    }
}
```

Usage in a controller:

```php
$sms = new \App\Services\SmsService();
$result = $sms->send($request->phone, 'Your OTP is 123456', 'otp-' . $user->id);
// $result['id'] can be polled at GET /api/v1/sms/{id}
```

For slow/failure-tolerant sends, wrap in a queued job so your app never blocks on SMS.

---

## React (SPA)

**The browser never calls this API directly.** A Bearer key in React code is visible to every visitor (devtools, bundle dump) and will be abused.

Correct pattern:

```
React UI ──fetch──▶ Your backend (Laravel / Node / PHP)
                        │ holds SMS_API_KEY server-side
                        └──POST──▶ JE Lite SMS API
```

Example:

```jsx
// React component — calls YOUR backend only
async function requestOtp(phone) {
  const res = await fetch('/api/otp', {           // your backend route
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone }),
  });
  return res.json(); // your backend decides what to expose
}
```

```js
// Your Node/Express backend route (server-side)
app.post('/api/otp', async (req, res) => {
  const r = await fetch(`${process.env.SMS_API_URL}/api/v1/sms/send`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${process.env.SMS_API_KEY}`, // server-side only
    },
    body: JSON.stringify({
      to: req.body.phone,
      message: `Your OTP is ${otp}`,
      client_ref: `otp-${userId}`,
    }),
  });
  res.status(r.status).json({ ok: r.ok });
});
```

Anti-pattern (never do this):

```js
// ❌ Exposes the key to every visitor
fetch('http://.../api/v1/sms/send', {
  headers: { Authorization: `Bearer ${import.meta.env.VITE_SMS_KEY}` },
});
```

---

## Testing your integration

- Use **Admin → Test** (`/admin/test`) to reproduce exactly what your app sends and see the raw response.
- Idempotency: reuse a `client_ref` for business transactions (e.g. `leave-{id}`) so retries never double-send.
- Rate limits are per key per minute; ask the admin to raise them for bulk use cases.
