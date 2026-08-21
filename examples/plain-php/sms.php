<?php

declare(strict_types=1);

// Load env vars from .env when the shell doesn't provide them.
if (getenv('SMS_API_KEY') === false || getenv('SMS_API_KEY') === '') {
    $envFile = __DIR__ . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
        }
    }
}

function smsConfig(): array
{
    return [
        'url' => rtrim(getenv('SMS_API_URL') ?: 'http://localhost/projects/jelite_sms_api', '/'),
        'key' => getenv('SMS_API_KEY') ?: '',
    ];
}

/** Send an SMS. Returns ['http'=>int, 'body'=>array|null]. */
function smsSend(string $to, string $message, ?string $clientRef = null): array
{
    ['url' => $url, 'key' => $key] = smsConfig();

    $ch = curl_init("{$url}/api/v1/sms/send");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array_filter([
            'to' => $to,
            'message' => $message,
            'client_ref' => $clientRef,
        ])),
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

/** Poll message status by id. Returns ['http'=>int, 'body'=>array|null]. */
function smsStatus(int $id): array
{
    ['url' => $url, 'key' => $key] = smsConfig();

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
