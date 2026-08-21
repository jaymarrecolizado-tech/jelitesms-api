# CodeIgniter 4 drop-in

Copy `SmsClient.php` into your app:

```
cp app/Libraries/SmsClient.php <your-ci-app>/app/Libraries/
```

## Environment (`.env`)

```ini
SMS_API_URL=http://localhost/projects/jelite_sms_api
SMS_API_KEY=jek_your_key_here
```

## Usage

```php
$sms = new \App\Libraries\SmsClient();

try {
    $result = $sms->send('09171234567', 'Your OTP is 123456', 'otp-9912');
    log_message('info', 'SMS queued id={id}', ['id' => $result['id']]);
} catch (\Throwable $e) {
    log_message('error', 'SMS failed: {err}', ['err' => $e->getMessage()]);
}

$status = $sms->status($result['id']);
```

Full tutorial: **Admin → Docs → CodeIgniter from scratch**.
