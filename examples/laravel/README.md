# Laravel drop-in

Copy these files into your Laravel app and adjust namespaces as needed.

| File | Destination |
|------|-------------|
| `config-services.php` | merge into `config/services.php` |
| `app/Services/SmsService.php` | `app/Services/SmsService.php` |
| `app/Jobs/SendSms.php` | `app/Jobs/SendSms.php` (optional queued sending) |

## Environment (`.env`)

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

## Usage

```php
use App\Services\SmsService;
use App\Jobs\SendSms;

$sms = app(SmsService::class);

// synchronous
$result = $sms->send('09171234567', 'Hello from Laravel', 'leave-1042');

// queued
SendSms::dispatch('09171234567', 'Hello from Laravel', 'leave-1042');

// status
$status = $sms->status($result['id']);
```

Full tutorial: **Admin → Docs → Laravel from scratch**.
