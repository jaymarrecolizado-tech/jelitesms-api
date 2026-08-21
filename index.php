<?php

declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use Jelite\App;

// Derive the install base from the filesystem (SCRIPT_NAME is unreliable
// under per-directory mod_rewrite, where it keeps the original request path).
$docRoot = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$projectDir = str_replace('\\', '/', __DIR__);
$base = str_starts_with(strtolower($projectDir), strtolower($docRoot))
    ? substr($projectDir, strlen($docRoot))
    : '';
$path = '/';
if (isset($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($base !== '' && str_starts_with(strtolower($path), strtolower($base))) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . ltrim($path, '/');
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
// Restore Authorization when mod_rewrite moved it into the environment.
if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

$result = App::fromConfig()->handle($_SERVER['REQUEST_METHOD'], $path, $headers, file_get_contents('php://input') ?: null);

http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE);
