# Consumer Integration Guide

How to send SMS from your app (plain PHP, Laravel, CodeIgniter, or React-backed projects) via the JE Lite SMS API.

## Quick start (5 minutes)

1. **Create a key** — log into the Admin UI (`http://localhost/projects/jelite_sms_api/admin`) → **API Keys** → create a named key for your app (e.g. `MyLaravelApp`). Copy the plaintext key — it is shown **once**.
2. **Store it server-side** — put it in your app's `.env` (never in browser code):
   ```ini
   SMS_API_URL=http://localhost/projects/jelite_sms_api
   SMS_API_KEY=jl_paste_your_key_here
   ```
3. **Send your first SMS** — jump to your stack: [Plain PHP](#plain-php) · [Laravel](#laravel) · [CodeIgniter](#codeigniter) · [React SPA](#react-spa).
4. **Confirm delivery** — **Admin → Messages** shows the queue row; **Admin → Usage** shows your app's counts. Use **Admin → Test** to reproduce any response without touching your code.
5. When the API moves to a production URL later, only `SMS_API_URL` changes — nothing else.

---

## Shared contract

- **Auth:** `Authorization: Bearer <key>` — one named key per app.
- **Send:** `POST /api/v1/sms/send` with JSON `{ "to", "message", "client_ref?" }`.

| Response | Meaning | What your app should do |
|----------|---------|-------------------------|
| `202` `{ "id": n, "status": "queued" }` | Accepted, queued for delivery | Store `id` if you want to poll status |
| `200` `{ "id": n, ... }` | Same `client_ref` seen before (idempotent replay) — no duplicate SMS | Treat as success |
| `400` `{ "error": "invalid_json" }` | Body is not valid JSON | Fix request encoding |
| `401` `{ "error": "unauthorized" }` | Missing/invalid/revoked key | Check `SMS_API_KEY`; do not retry blindly |
| `422` `{ "error": "validation_failed", "fields": { ... } }` | Bad phone/message/client_ref | Fix input from `fields` |
| `429` `{ "error": "rate_limited" }` | Key's per-minute limit exceeded | Wait and retry; ask admin to raise limit |

- **Status:** `GET /api/v1/sms/{id}` → `{ id, to, status, client_ref, attempts, error, created_at, sent_at }` where `status` is `queued → sending → sent | failed`. Only the key that created the message can read it.
- **Phone formats:** E.164 (`+639171234567`), local (`09171234567`), or bare (`9171234567`) all normalize to E.164.
- **Delivery model:** asynchronous — the queue worker runs every minute, so sends are usually near-instant but not guaranteed within a fixed time. Use `client_ref` on business transactions (e.g. `leave-{id}`) so retries never double-send.
- **Security:** never put the API key in browser code, `NEXT_PUBLIC_*` vars, Vite client env, or mobile bundles. Only server-side code calls this API.

Health probe (no auth):

```bash
curl http://localhost/projects/jelite_sms_api/api/v1/health
```

---

## Plain PHP

`.env`:

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jl_paste_your_key_here
```

`sms.php` — complete copy-paste helper:

```php
<?php

function smsConfig(string $key): string
{
    static $cfg = null;
    $cfg ??= parse_ini_file(__DIR__ . '/.env');
    return $cfg[$key] ?? '';
}

function smsBase(): string
{
    return rtrim(smsConfig('SMS_API_URL'), '/') . '/api/v1';
}

/**
 * @return array{http:int, body:?array}
 */
function smsSend(string $to, string $message, ?string $clientRef = null): array
{
    $payload = json_encode(array_filter([
        'to' => $to,
        'message' => $message,
        'client_ref' => $clientRef,
    ], static fn ($v) => $v !== null));

    $ch = curl_init(smsBase() . '/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . smsConfig('SMS_API_KEY'),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $status, 'body' => is_array($body) ? $body : null];
}

/**
 * @return array{http:int, body:?array}
 */
function smsStatus(int $id): array
{
    $ch = curl_init(smsBase() . '/sms/' . $id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . smsConfig('SMS_API_KEY')],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $status, 'body' => is_array($body) ? $body : null];
}
```

Usage with full error handling:

```php
require __DIR__ . '/sms.php';

$result = smsSend('09171234567', 'Your leave request was approved.', 'leave-42');

match (true) {
    $result['http'] === 202 => null,                                        // queued; $result['body']['id'] available
    $result['http'] === 200 => null,                                        // duplicate client_ref — already sent
    $result['http'] === 401 => error_log('SMS API key invalid'),
    $result['http'] === 422 => error_log('SMS input invalid: ' . json_encode($result['body']['fields'] ?? [])),
    $result['http'] === 429 => error_log('SMS rate limited — retry in a minute'),
    default                 => error_log('SMS API unreachable'),
};

// Later:
$check = smsStatus((int) $result['body']['id']);
echo $check['body']['status']; // queued | sending | sent | failed
```

Copy-paste test commands (Windows PowerShell / cmd):

```bat
curl -X POST "http://localhost/projects/jelite_sms_api/api/v1/sms/send" -H "Authorization: Bearer YOUR_KEY" -H "Content-Type: application/json" -d "{\"to\":\"09171234567\",\"message\":\"Hello\"}"

curl "http://localhost/projects/jelite_sms_api/api/v1/sms/1" -H "Authorization: Bearer YOUR_KEY"

curl "http://localhost/projects/jelite_sms_api/api/v1/health"
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

`app/Services/SmsService.php` — send + status + explicit HTTP-code mapping:

```php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsService
{
    public function send(string $to, string $message, ?string $clientRef = null): array
    {
        $response = Http::withToken(config('services.sms.key'))
            ->timeout(15)
            ->post(rtrim((string) config('services.sms.url'), '/') . '/api/v1/sms/send', array_filter([
                'to' => $to,
                'message' => $message,
                'client_ref' => $clientRef,
            ]));

        return match ($response->status()) {
            202, 200 => $response->json(),                       // queued / idempotent replay
            401      => throw new RuntimeException('SMS API key invalid — check services.sms.key'),
            422      => throw new \InvalidArgumentException('SMS input invalid: ' . $response->body()),
            429      => throw new RuntimeException('SMS rate limited — retry shortly', 429),
            default  => throw new RuntimeException('SMS API unavailable (HTTP ' . $response->status() . ')'),
        };
    }

    public function status(int $id): ?array
    {
        $response = Http::withToken(config('services.sms.key'))
            ->timeout(15)
            ->get(rtrim((string) config('services.sms.url'), '/') . "/api/v1/sms/{$id}");

        return $response->successful() ? $response->json() : null;
    }
}
```

Usage in a controller:

```php
$sms = new \App\Services\SmsService();
$result = $sms->send($request->phone, 'Your OTP is 123456', 'otp-' . $user->id);
// $result['id'], $result['status']
```

Queued job example (so your app never blocks on SMS):

```php
namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private string $to,
        private string $message,
        private string $clientRef
    ) {
    }

    public function handle(SmsService $sms): void
    {
        // client_ref makes retries safe — the API deduplicates.
        $sms->send($this->to, $this->message, $this->clientRef);
    }
}

// Dispatch from anywhere:
SendSms::dispatch($phone, 'Your leave was approved', "leave-{$leave->id}");
```

Reminder: call `SmsService` from PHP code (controllers/jobs/services) only — never render secrets into Blade templates or JS.

---

## CodeIgniter 4

`.env`:

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jl_paste_your_key_here
```

Using CI4's built-in `CURLRequest`:

```php
use CodeIgniter\HTTP\CURLRequest;

public function sendSms(string $to, string $message, ?string $clientRef = null): array
{
    $client = service('curlrequest');
    $response = $client->post(rtrim(env('SMS_API_URL'), '/') . '/api/v1/sms/send', [
        'headers' => [
            'Authorization' => 'Bearer ' . env('SMS_API_KEY'),
            'Content-Type'  => 'application/json',
        ],
        'json'    => array_filter([
            'to'         => $to,
            'message'    => $message,
            'client_ref' => $clientRef,
        ]),
        'timeout' => 15,
    ]);

    // 202 queued · 200 replay · 401 bad key · 422 bad input · 429 rate limited
    return ['http' => $response->getStatusCode(), 'body' => json_decode($response->getBody(), true)];
}
```

---

## React SPA

**The browser never calls this API directly.** A Bearer key in React code is visible to every visitor (devtools, bundle dump) and will be abused. The UI should only ever see business-level responses like `{ ok: true }` — never the SMS API key or gateway credentials.

### Pattern A — Node/Express backend (BFF)

```jsx
// React component — calls YOUR backend only
async function requestOtp(phone) {
  const res = await fetch('/api/otp', {           // your backend route
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone }),
  });
  return res.json(); // { ok: true } or a business error message
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

### Pattern B — Laravel backend (BFF)

Common for DICT apps: React frontend + Laravel API backend. The Laravel app owns the SMS key and exposes its own endpoint.

`routes/api.php`:

```php
Route::middleware('auth:sanctum')->post('/announcements', [AnnouncementController::class, 'store']);
```

`AnnouncementController`:

```php
public function store(Request $request, \App\Services\SmsService $sms)
{
    $data = $request->validate([
        'title'   => 'required|string|max:100',
        'body'    => 'required|string|max:320',
        'phones'  => 'required|array|min:1',
    ]);

    foreach ($data['phones'] as $i => $phone) {
        \App\Jobs\SendSms::dispatch($phone, "{$data['title']}: {$data['body']}", "ann-{$request->id}-{$i}");
    }

    // React only learns the business outcome — no SMS keys, no gateway details.
    return response()->json(['ok' => true]);
}
```

React side:

```jsx
const res = await fetch('/api/announcements', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${userToken}`,   // YOUR app's auth token, not the SMS key
  },
  body: JSON.stringify({ title, body, phones }),
});
const { ok } = await res.json();
```

Anti-pattern (never do this):

```js
// ❌ Exposes the key to every visitor
fetch('http://.../api/v1/sms/send', {
  headers: { Authorization: `Bearer ${import.meta.env.VITE_SMS_KEY}` },
});
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `401 unauthorized` | Wrong/revoked key, or header missing | Re-copy the key into `.env`; confirm header is `Authorization: Bearer <key>`; create a new key in Admin → API Keys |
| `422 validation_failed` with `fields.to` | Phone format unrecognized | Use `09XXXXXXXXX`, `9XXXXXXXXX`, or `+639XXXXXXXXX` |
| `422` with `fields.message` | Empty or over-length message | Max length shown in Admin → Settings (`SMS_MAX_MESSAGE_LENGTH`, default 320) |
| `429 rate_limited` | Key exceeded its per-minute limit | Back off and retry; ask the admin to raise the key's rate in Admin → API Keys |
| Message stuck at `queued` | Worker not running | Local: run `powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1` (or Admin → Test → "Run worker once"). Server: install the cron line from `docs/DEPLOY.md` |
| Message `failed` with HTTP error in Admin → Messages | Gateway rejected/down | Check Admin → Test → *Check configuration*; verify gateway credentials in Admin → Settings |
| Sent but phone got nothing after minutes | Device offline/no signal, or carrier delay | Ask admin to check the Android SMS Gateway app is connected; inspect state via `php bin/check-message.php <gateway_message_id>` |
| `200` instead of expected `202` | Duplicate `client_ref` replayed | Expected idempotency behavior — use a fresh `client_ref` for genuinely new messages |
| Connection refused / timeout | API base URL wrong or Apache down | Verify `SMS_API_URL`; open `/api/v1/health` in a browser |

Still stuck? Use **Admin → Test** to reproduce the exact request and see the raw JSON response.
