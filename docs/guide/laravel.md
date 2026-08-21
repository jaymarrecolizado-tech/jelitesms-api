# Laravel from scratch

Integrate in four files: `.env`, `config/services.php`, a service class, and
a controller (plus an optional queued job). Drop-in copies live in
[`examples/laravel/`](../../../examples/laravel/).

## 1. Environment — `.env`

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

## 2. Config — `config/services.php`

```php
'sms' => [
    'url' => env('SMS_API_URL', 'http://localhost/projects/jelite_sms_api'),
    'key' => env('SMS_API_KEY'),
],
```

## 3. Service — `app/Services/SmsService.php`

```php
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
            202, 200 => $response->json(),   // 202 queued · 200 idempotent replay
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
```

## 4. Call it from a controller

```php
public function approve(Request $request, Leave $leave, SmsService $sms)
{
    // ... business logic ...

    $result = $sms->send(
        $leave->employee_phone,
        "Your leave request #{$leave->id} was approved.",
        "leave-{$leave->id}"
    );

    return back()->with('sms_id', $result['id']);
}
```

Call the service from controllers, jobs, or services — **never from Blade**
with an embedded key.

## 5. Optional: queue it

Slow gateway? Don't make users wait — the API call only *enqueues* anyway,
but a job keeps your controller snappy even when the SMS API is slow:

```php
// app/Jobs/SendSms.php
class SendSms implements ShouldQueue
{
    public function __construct(
        private string $to,
        private string $message,
        private ?string $clientRef = null,
    ) {}

    public function handle(SmsService $sms): void
    {
        $sms->send($this->to, $this->message, $this->clientRef);
    }
}

// usage
SendSms::dispatch($phone, $text, "leave-{$leave->id}");
```

Next: **CodeIgniter from scratch**.
