<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SmsService
{
    public function send(string $to, string $message, ?string $clientRef = null): array
    {
        $response = Http::withToken(config('services.sms.key'))
            ->timeout(10)
            ->post(rtrim(config('services.sms.url'), '/') . '/api/v1/sms/send', array_filter([
                'to' => $to,
                'message' => $message,
                'client_ref' => $clientRef,
            ]));

        return match ($response->status()) {
            202, 200 => $response->json(),
            401      => throw new RuntimeException('Invalid SMS API key'),
            422      => throw new \InvalidArgumentException('SMS validation: ' . $response->body()),
            429      => throw new RuntimeException('SMS rate limited'),
            default  => throw new RuntimeException('SMS API unavailable (' . $response->status() . ')'),
        };
    }

    public function status(int $id): ?array
    {
        $response = Http::withToken(config('services.sms.key'))
            ->timeout(10)
            ->get(rtrim(config('services.sms.url'), '/') . "/api/v1/sms/{$id}");

        return $response->successful() ? $response->json() : null;
    }
}
