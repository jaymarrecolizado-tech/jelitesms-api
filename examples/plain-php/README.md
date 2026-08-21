# Plain PHP example

Dependency-free integration with JE Lite SMS API. Works on PHP 7.4+ with curl.

## Setup

```bash
cp .env.example .env   # then edit SMS_API_KEY
```

Or set the variables in your shell:

```bash
# Windows
set SMS_API_URL=http://localhost/projects/jelite_sms_api
set SMS_API_KEY=jek_your_key_here

# Linux/macOS
export SMS_API_URL=http://localhost/projects/jelite_sms_api
export SMS_API_KEY=jek_your_key_here
```

## Send

```bash
php send.php 09171234567 "Hello from plain PHP"
php send.php 09171234567 "Idempotent message" leave-1042
```

## Check status

```bash
php status.php 42
```

## Use in your own project

Copy `sms.php`, load your env values into `smsConfig()`, and call
`smsSend()` / `smsStatus()`. See the full tutorial in **Admin → Docs →
Plain PHP from scratch**.
