# CodeIgniter from scratch

CodeIgniter 4 integration using the built-in CURLRequest library. A drop-in
copy lives in [`examples/codeigniter/`](../../../examples/codeigniter/).

## 1. Environment — `.env`

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

## 2. Library — `app/Libraries/SmsClient.php`

```php
<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;

class SmsClient
{
    private CURLRequest $client;

    public function __construct()
    {
        $url = rtrim(env('SMS_API_URL') ?? 'http://localhost/projects/jelite_sms_api', '/');
        $this->client = \Config\Services::curlrequest([
            'baseURI' => $url,
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . (env('SMS_API_KEY') ?? ''),
                'Content-Type'  => 'application/json',
            ],
        ]);
    }

    /** @throws RuntimeException on non-2xx */
    public function send(string $to, string $message, ?string $clientRef = null): array
    {
        $payload = json_encode(array_filter([
            'to'         => $to,
            'message'    => $message,
            'client_ref' => $clientRef,
        ]));

        $response = $this->client->post('/api/v1/sms/send', ['body' => $payload]);
        $code = $response->getStatusCode();

        if ($code === 202 || $code === 200) {
            return json_decode($response->getBody(), true) ?? [];
        }

        throw new RuntimeException("SMS API error HTTP {$code}: " . $response->getBody());
    }

    public function status(int $id): ?array
    {
        $response = $this->client->get("/api/v1/sms/{$id}");
        return $response->isSuccessful()
            ? json_decode($response->getBody(), true)
            : null;
    }
}
```

## 3. Controller usage

```php
$sms = new \App\Libraries\SmsClient();

try {
    $result = $sms->send('09171234567', 'Your OTP is 123456', 'otp-9912');
    log_message('info', 'SMS queued id={id}', ['id' => $result['id']]);
} catch (\Throwable $e) {
    log_message('error', 'SMS failed: {err}', ['err' => $e->getMessage()]);
}
```

That's it — same contract as every other stack: `202` + `{id, status}`,
`client_ref` for idempotency, Bearer key server-side only.

Next: **React + Laravel BFF**.
