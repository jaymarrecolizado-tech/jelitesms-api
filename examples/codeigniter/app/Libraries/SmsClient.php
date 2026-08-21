<?php

declare(strict_types=1);

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
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /** @throws RuntimeException on non-2xx */
    public function send(string $to, string $message, ?string $clientRef = null): array
    {
        $payload = json_encode(array_filter([
            'to' => $to,
            'message' => $message,
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
