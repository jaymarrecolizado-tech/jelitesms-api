<?php

declare(strict_types=1);

section('Prod export');

$root = dirname(__DIR__);
$prod = $root . '/prod/jelite_sms_api';

// Rebuild via the real CLI so the deployed artifact is exactly what ships.
exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/export-prod.php'), $out, $code);
same(0, $code, 'export-prod.php exits 0');

foreach (
    [
        'index.php',
        '.htaccess',
        '.env.example',
        'src/App.php',
        'src/Config.php',
        'bin/worker.php',
        'bin/setup.php',
        'bin/sync-delivery.php',
        'database/schema.sql',
        'docs/CONSUMERS.md',
        'docs/guide/welcome.md',
        'docs/guide/laravel.md',
        'storage/.gitkeep',
    ] as $rel
) {
    check(is_file("{$prod}/{$rel}"), "includes {$rel}");
}

foreach (
    [
        '.env',
        'tests',
        'examples',
        'PLAN.md',
        'README.md',
        'docs/ops',
        'docs/DEPLOY.md',
        'bin/export-prod.php',
        'bin/register-worker-task.ps1',
    ] as $rel
) {
    check(!file_exists("{$prod}/{$rel}"), "excludes {$rel}");
}

check(is_dir($prod . '/storage'), 'storage dir present');
