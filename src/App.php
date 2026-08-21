<?php

namespace Jelite;

/**
 * HTTP application core. Dispatch is pure (method/path/headers/body in,
 * status/JSON out) so tests can call App::handle() without a web server.
 */
class App
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private SmsRepository $sms,
        private ?SmsGateway $gateway
    ) {
    }

    public static function fromConfig(?SmsGateway $gateway = null): self
    {
        $db = Database::pdo();
        return new self(new ApiKeyRepository($db), new SmsRepository($db), $gateway ?? SmsGateway::fromConfig());
    }

    /**
     * @param array<string,string> $headers lowercase header names
     * @return array{status:int,body:array}
     */
    public function handle(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        try {
            if ($path === '/api/v1/health' && $method === 'GET') {
                return $this->health();
            }

            if ($path === '/api/v1/sms/send' && $method === 'POST') {
                return $this->authenticated($headers, fn (array $key) => $this->send($key, $body));
            }

            if (preg_match('#^/api/v1/sms/(\d+)$#', $path, $m) && $method === 'GET') {
                return $this->authenticated($headers, fn (array $key) => $this->status((int) $m[1], $key));
            }

            return self::json(404, ['error' => 'not_found', 'message' => 'Unknown endpoint']);
        } catch (\PDOException $e) {
            return self::json(500, ['error' => 'storage_error', 'message' => 'Database unavailable']);
        }
    }

    /**
     * @param callable(array):array{status:int,body:array} $next
     * @return array{status:int,body:array}
     */
    private function authenticated(array $headers, callable $next): array
    {
        $auth = trim($headers['authorization'] ?? '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return self::json(401, ['error' => 'unauthorized', 'message' => 'Missing or malformed Authorization: Bearer header']);
        }

        $key = $this->apiKeys->authenticate($m[1]);
        if ($key === null) {
            return self::json(401, ['error' => 'unauthorized', 'message' => 'Invalid or revoked API key']);
        }

        return $next($key);
    }

    /**
     * Effective health body (database + gateway reachability), no secrets.
     * Used by GET /api/v1/health and the admin Test page probe.
     */
    public function probe(): array
    {
        return $this->health()['body'];
    }

    /**
     * Public send path for a resolved API-key row (admin Test page reuses
     * this so consumers and operators get identical validation/enqueue).
     *
     * @param array $apiKey api_keys row
     * @return array{status:int,body:array}
     */
    public function sendRaw(array $apiKey, ?string $rawBody): array
    {
        return $this->send($apiKey, $rawBody);
    }

    private function send(array $apiKey, ?string $rawBody): array
    {
        $data = json_decode((string) $rawBody, true);
        if (!is_array($data)) {
            return self::json(400, ['error' => 'invalid_json', 'message' => 'Request body must be a JSON object']);
        }

        $errors = [];

        $to = trim((string) ($data['to'] ?? ''));
        $toE164 = Phone::toE164($to, Config::get('SMS_DEFAULT_COUNTRY_CODE', '63'));
        if ($toE164 === null) {
            $errors['to'] = 'Must be a valid phone number (E.164 or local format)';
        }

        $message = (string) ($data['message'] ?? '');
        $maxLength = Config::int('SMS_MAX_MESSAGE_LENGTH', 320);
        if (trim($message) === '') {
            $errors['message'] = 'Message is required';
        } elseif (mb_strlen($message) > $maxLength) {
            $errors['message'] = "Message exceeds {$maxLength} characters";
        }

        $clientRef = isset($data['client_ref']) ? trim((string) $data['client_ref']) : null;
        if ($clientRef !== null && ($clientRef === '' || strlen($clientRef) > 100)) {
            $errors['client_ref'] = 'client_ref must be 1-100 characters';
        }
        if ($clientRef === '') {
            $clientRef = null;
        }

        if ($errors !== []) {
            return self::json(422, ['error' => 'validation_failed', 'fields' => $errors]);
        }

        // Rate limit per key.
        $limit = (int) $apiKey['rate_limit_per_minute'];
        if ($this->sms->countRecentFor((int) $apiKey['id']) >= $limit) {
            return self::json(429, ['error' => 'rate_limited', 'message' => "Rate limit of {$limit} messages/minute exceeded"]);
        }

        $result = $this->sms->enqueue((int) $apiKey['id'], $toE164, $message, $clientRef);
        $status = $result['created'] ? 202 : 200;
        return self::json($status, [
            'id' => (int) $result['message']['id'],
            'status' => $result['message']['status'],
        ]);
    }

    private function status(int $id, array $apiKey): array
    {
        $message = $this->sms->find($id);
        if ($message === null || (int) $message['api_key_id'] !== (int) $apiKey['id']) {
            return self::json(404, ['error' => 'not_found', 'message' => 'Message not found']);
        }

        return self::json(200, [
            'id' => (int) $message['id'],
            'to' => $message['to_e164'],
            'status' => $message['status'],
            'client_ref' => $message['client_ref'],
            'attempts' => (int) $message['attempts'],
            'error' => $message['error'],
            'created_at' => $message['created_at'],
            'sent_at' => $message['sent_at'],
        ]);
    }

    private function health(): array
    {
        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
            $dbError = null;
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbError = 'Database unreachable';
        }

        $gatewayConfigured = $this->gateway !== null;
        $gatewayOk = null;
        if ($gatewayConfigured) {
            $h = $this->gateway->health();
            $gatewayOk = $h['ok'];
        }

        $ok = $dbOk && $gatewayOk !== false;
        return self::json($ok ? 200 : 503, [
            'ok' => $ok,
            'database' => $dbOk ? 'up' : 'down',
            'gateway_configured' => $gatewayConfigured,
            'gateway' => $gatewayOk === null ? 'unknown' : ($gatewayOk ? 'reachable' : 'unreachable'),
        ]);
    }

    /**
     * @return array{status:int,body:array}
     */
    public static function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
