<?php

declare(strict_types=1);

use Jelite\SmsGateway;

section('SmsGateway client');

section('Gateway: URL normalization');
same('https://api.sms-gate.app', SmsGateway::normalizeBaseUrl('https://api.sms-gate.app/'), 'trailing slash stripped');
same('https://api.sms-gate.app', SmsGateway::normalizeBaseUrl('http://api.sms-gate.app:443'), 'http+443 normalized');
same('https://api.sms-gate.app', SmsGateway::normalizeBaseUrl('api.sms-gate.app'), 'scheme added');
same('http://192.168.1.10:8080', SmsGateway::normalizeBaseUrl('http://192.168.1.10:8080/'), 'local URL kept');

section('Gateway: request building + response parsing');
$gwRequests = [];
$transport = function (string $url, ?string $payload, bool $headOnly) use (&$gwRequests): array {
    $gwRequests[] = ['url' => $url, 'payload' => $payload];
    return ['body' => json_encode(['id' => 'gw-abc-1']), 'errno' => 0, 'error' => '', 'code' => 202, 'final_url' => $url];
};
$gw = new SmsGateway('https://api.sms-gate.app', 'u', 'p', '/3rdparty/v1/messages', 15, $transport);

$res = $gw->send('+639171234567', 'test');
check($res['ok'], 'send ok on 202');
same('gw-abc-1', $res['message_id'], 'message id parsed');
same('https://api.sms-gate.app/3rdparty/v1/messages', $gwRequests[0]['url'], 'cloud path used');
$payload = json_decode((string) $gwRequests[0]['payload'], true);
same(['text' => 'test'], $payload['textMessage'], 'textMessage payload shape');
same(['+639171234567'], $payload['phoneNumbers'], 'phoneNumbers payload shape');

$gw = new SmsGateway('https://api.sms-gate.app', 'u', 'p', '/wrong/path', 15, $transport);
$gw->send('+639171234567', 'x');
same('https://api.sms-gate.app/3rdparty/v1/messages', end($gwRequests)['url'], 'cloud forces /3rdparty/v1/messages');

section('Gateway: failure handling');
$failTransport = fn () => ['body' => 'nope', 'errno' => 7, 'error' => 'connection refused', 'code' => 0, 'final_url' => 'mock'];
$gw = new SmsGateway('http://192.168.1.10:8080', 'u', 'p', '/message', 15, $failTransport);
$res = $gw->send('+639171234567', 'x');
check(!$res['ok'], 'curl error → not ok');
check($res['error'] !== null && str_contains((string) $res['error'], 'connection refused'), 'error surfaced');

$httpFail = fn () => ['body' => '{"message":"bad credentials"}', 'errno' => 0, 'error' => '', 'code' => 401, 'final_url' => 'mock'];
$gw = new SmsGateway('http://192.168.1.10:8080', 'u', 'p', '/message', 15, $httpFail);
$res = $gw->send('+639171234567', 'x');
check(!$res['ok'], 'HTTP 401 → not ok');
check(str_contains((string) $res['error'], 'HTTP 401'), 'HTTP code in error');
