<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSms implements ShouldQueue
{
    use Queueable;

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
