<?php

namespace Jelite;

/**
 * SMS Gateway HTTP client (capcom6 / sms-gate.app compatible).
 * Ported from LOKA's SmsGateway; transport is injectable for tests.
 */
class SmsGateway
{
    private string $baseUrl;
    private string $apiPath;
    private string $username;
    private string $password;
    private int $timeout;

    /** @var null|callable(string,string,array):array{body:string|false,errno:int,error:string,code:int,final_url:string} */
    private $transport;

    public function __construct(
        string $baseUrl,
        string $username,
        string $password,
        string $apiPath = '/message',
        int $timeout = 15,
        ?callable $transport = null
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        // Public cloud always uses the cloud messages path.
        if (stripos($this->baseUrl, 'api.sms-gate.app') !== false) {
            $apiPath = '/3rdparty/v1/messages';
        }
        $this->apiPath = '/' . ltrim($apiPath, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout = max(5, $timeout);
        $this->transport = $transport;
    }

    public static function fromConfig(?callable $transport = null): ?self
    {
        $url = trim(Config::get('SMS_GATEWAY_URL'));
        $user = trim(Config::get('SMS_GATEWAY_USERNAME'));
        $pass = Config::get('SMS_GATEWAY_PASSWORD');
        if ($url === '' || $user === '' || $pass === '') {
            return null;
        }

        $apiPath = Config::get('SMS_API_PATH', '/3rdparty/v1/messages');

        // Public cloud always uses the cloud messages path.
        if (stripos(self::normalizeBaseUrl($url), 'api.sms-gate.app') !== false) {
            $apiPath = '/3rdparty/v1/messages';
        }

        return new self(
            $url,
            $user,
            $pass,
            $apiPath,
            Config::int('SMS_TIMEOUT_SECONDS', 15),
            $transport
        );
    }

    public function endpoint(): string
    {
        return $this->baseUrl . $this->apiPath;
    }

    /**
     * Normalize user-entered gateway base (handles app copy "api.sms-gate.app:443").
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#/+$#', '', $url) ?? $url;

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        // http → https for sms-gate (avoids HTTP 308 Permanent Redirect on POST).
        if (stripos($url, 'sms-gate.app') !== false) {
            $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
            $url = preg_replace('#^(https://[^/:]+):443(?=/|$)#i', '$1', $url) ?? $url;
        }

        return rtrim($url, '/');
    }

    /**
     * @return array{ok:bool,message_id:?string,response:?string,error:?string,http_code:int}
     */
    public function send(string $phoneE164, string $message): array
    {
        $payload = json_encode([
            'textMessage' => ['text' => $message],
            'phoneNumbers' => [$phoneE164],
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return ['ok' => false, 'message_id' => null, 'response' => null, 'error' => 'Failed to encode SMS payload', 'http_code' => 0];
        }

        $result = $this->request($this->endpoint(), $payload);
        $code = $result['code'];
        $body = $result['body'];

        if ($result['errno'] !== 0) {
            return [
                'ok' => false,
                'message_id' => null,
                'response' => is_string($body) ? $body : null,
                'error' => $result['error'] !== '' ? $result['error'] : 'cURL error ' . $result['errno'],
                'http_code' => $code,
            ];
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        $messageId = null;
        if (is_array($decoded)) {
            $messageId = $decoded['id'] ?? $decoded['messageId'] ?? null;
            $messageId = is_string($messageId) ? substr($messageId, 0, 100) : null;
        }

        $ok = $code >= 200 && $code < 300;
        $errDetail = '';
        if (!$ok) {
            $errDetail = 'HTTP ' . $code;
            if (is_string($body) && $body !== '') {
                $errDetail .= ': ' . substr($body, 0, 200);
            }
            $errDetail .= ' @ ' . $result['final_url'];
        }

        return [
            'ok' => $ok,
            'message_id' => $messageId,
            'response' => is_string($body) ? substr($body, 0, 4000) : null,
            'error' => $ok ? null : $errDetail,
            'http_code' => $code,
        ];
    }

    /**
     * Fetch upstream delivery state for a gateway message id
     * (GET {apiPath}/{id}, Basic auth). Upstream states: Pending, Processed,
     * Sent, Delivered, Failed, Cancelling, Cancelled.
     *
     * @return array{ok:bool,state:?string,reason:?string,error:?string,http_code:int}
     */
    public function getState(string $gatewayMessageId): array
    {
        $url = $this->endpoint() . '/' . rawurlencode($gatewayMessageId);
        $result = $this->request($url, null, false, true);
        $code = $result['code'];
        $body = $result['body'];

        if ($result['errno'] !== 0) {
            return [
                'ok' => false,
                'state' => null,
                'reason' => null,
                'error' => $result['error'] !== '' ? $result['error'] : 'cURL error ' . $result['errno'],
                'http_code' => $code,
            ];
        }

        if ($code < 200 || $code >= 300) {
            return [
                'ok' => false,
                'state' => null,
                'reason' => null,
                'error' => 'HTTP ' . $code . ' @ ' . $result['final_url'],
                'http_code' => $code,
            ];
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        $state = is_array($decoded) ? ($decoded['state'] ?? null) : null;
        $reason = is_array($decoded) ? ($decoded['reason'] ?? null) : null;

        return [
            'ok' => is_string($state) && $state !== '',
            'state' => is_string($state) ? $state : null,
            'reason' => is_string($reason) ? substr($reason, 0, 200) : null,
            'error' => is_string($state) && $state !== '' ? null : 'No state in gateway response',
            'http_code' => $code,
        ];
    }

    /**
     * @return array{ok:bool,error:?string,http_code:int,body:?string}
     */
    public function health(): array
    {
        // Public cloud has no /health; probe the API host instead.
        $isCloud = stripos($this->baseUrl, 'sms-gate.app') !== false;
        $url = $isCloud ? $this->baseUrl . '/' : ($this->baseUrl . '/health');
        $result = $this->request($url, null, $isCloud);
        $code = $result['code'];
        $body = $result['body'];

        if ($result['errno'] !== 0) {
            return ['ok' => false, 'error' => $result['error'] ?: 'cURL error', 'http_code' => $code, 'body' => null];
        }

        if ($isCloud) {
            return [
                'ok' => $code > 0,
                'error' => $code > 0 ? null : 'No HTTP response from cloud host',
                'http_code' => $code,
                'body' => is_string($body) ? substr($body, 0, 500) : null,
            ];
        }

        $ok = $code >= 200 && $code < 300;
        return [
            'ok' => $ok,
            'error' => $ok ? null : ('HTTP ' . $code),
            'http_code' => $code,
            'body' => is_string($body) ? substr($body, 0, 500) : null,
        ];
    }

    /**
     * @return array{body:string|false,errno:int,error:string,code:int,final_url:string}
     */
    private function request(string $url, ?string $payload, bool $headOnly = false, bool $withAuth = false): array
    {
        if ($this->transport !== null) {
            return $this->transport($url, $payload, $headOnly);
        }

        $connectTimeout = min(3, $this->timeout);
        $readTimeout = min($this->timeout, 15);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $readTimeout,
            CURLOPT_CONNECTTIMEOUT => max(5, $connectTimeout),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => $headOnly,
        ];
        if ($payload !== null || $withAuth) {
            if ($payload !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $payload;
                $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
            }
            $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return ['body' => $body, 'errno' => $errno, 'error' => $error, 'code' => $code, 'final_url' => $finalUrl];
    }

    private function transport(string $url, ?string $payload, bool $headOnly): array
    {
        return ($this->transport)($url, $payload, $headOnly);
    }
}
