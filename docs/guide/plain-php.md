# Plain PHP from scratch

A complete, dependency-free integration for any PHP project (7.4+). A
runnable copy lives in [`examples/plain-php/`](../../../examples/plain-php/).

## 1. Config

`.env` (or your existing config):

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

> Production: swap `SMS_API_URL` for the live URL. Nothing else changes.

## 2. The helper — `sms.php`

```php
<?php

function smsConfig(): array
{
    return [
        'url' => getenv('SMS_API_URL') ?: 'http://localhost/projects/jelite_sms_api',
        'key' => getenv('SMS_API_KEY') ?: '',
    ];
}

/** Send an SMS. Returns ['http'=>int, 'body'=>array|null]. */
function smsSend(string $to, string $message, ?string $clientRef = null): array
{
    [$url, $key] = smsConfig();
    $payload = json_encode(array_filter([
        'to' => $to,
        'message' => $message,
        'client_ref' => $clientRef,
    ]));

    $ch = curl_init("{$url}/api/v1/sms/send");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $code, 'body' => is_array($body) ? $body : null];
}

/** Poll message status by id. */
function smsStatus(int $id): array
{
    [$url, $key] = smsConfig();
    $ch = curl_init("{$url}/api/v1/sms/{$id}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key],
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $code, 'body' => is_array($body) ? $body : null];
}
```

## 3. Use it

```php
require __DIR__ . '/sms.php';

$result = smsSend('09171234567', 'Your leave request was approved.', 'leave-1042');

match (true) {
    $result['http'] === 202 => error_log('SMS queued, id=' . $result['body']['id']),
    $result['http'] === 200 => null,                                          // client_ref replay — already sent
    $result['http'] === 401 => throw new RuntimeException('Bad SMS API key'),
    $result['http'] === 422 => throw new InvalidArgumentException(json_encode($result['body']['fields'])),
    $result['http'] === 429 => throw new RuntimeException('Rate limited — retry later'),
    default                 => throw new RuntimeException('SMS API unavailable'),
};
```

## 4. Run the sample

```bash
cd examples/plain-php
set SMS_API_KEY=jek_your_key_here   # Windows; use export on Linux
php send.php 09171234567 "Hello from plain PHP"
php status.php 42
```

Next: **Laravel from scratch**.
