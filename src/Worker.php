<?php

namespace Jelite;

/**
 * Queue drain logic shared by bin/worker.php (cron) and the admin Test page.
 */
class Worker
{
    /**
     * Claim and send one batch of queued messages.
     *
     * @param SmsGateway|null $gateway null = build from config
     * @param int|null $limit null = WORKER_BATCH_SIZE config
     * @param callable|null $log optional fn(string $line):void for progress output
     * @return int messages processed, or -1 when the gateway is not configured
     */
    public static function drain(?SmsGateway $gateway = null, ?int $limit = null, ?callable $log = null): int
    {
        $gateway ??= SmsGateway::fromConfig();
        if ($gateway === null) {
            return -1;
        }

        $limit ??= Config::int('WORKER_BATCH_SIZE', 20);
        $maxAttempts = Config::int('SMS_MAX_ATTEMPTS', 3);
        $repo = new SmsRepository(Database::pdo());

        $messages = $repo->claimQueued($limit, $maxAttempts);
        foreach ($messages as $message) {
            $result = $gateway->send($message['to_e164'], $message['body']);
            if ($result['ok']) {
                $repo->markSent((int) $message['id'], $result['message_id']);
                $log !== null && $log("[sent] #{$message['id']} to {$message['to_e164']}");
            } else {
                $repo->markFailed((int) $message['id'], (string) $result['error'], $maxAttempts);
                $log !== null && $log("[retry/fail] #{$message['id']}: {$result['error']}");
            }
        }

        return count($messages);
    }
}
