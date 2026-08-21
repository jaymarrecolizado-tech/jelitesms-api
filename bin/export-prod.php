<?php

declare(strict_types=1);

// Builds prod/jelite_sms_api/ — the deploy-ready staging tree uploaded to
// Hostinger public_html. Wipes/recreates the folder on every run.
//
// Include: front controller, .htaccess, .env.example, src/, bin/*.php,
//          database/, docs/guide/, docs/CONSUMERS.md, empty storage/.
// Exclude: .env, .git, tests/, examples/, PLAN.md, README, docs/ops/,
//          Windows .ps1 scripts, logs.

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\Config;

Config::load(dirname(__DIR__) . '/.env');

$root = dirname(__DIR__);
$dest = $root . '/prod/jelite_sms_api';

$dirs = [
    'src',
    'database',
    'docs/guide',
];

$binScripts = array_filter(
    glob($root . '/bin/*.php') ?: [],
    static fn (string $f): bool => basename($f) !== 'export-prod.php'
);

// Wipe and recreate.
if (is_dir($dest)) {
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        exec('rd /s /q ' . escapeshellarg($dest));
    } else {
        exec('rm -rf ' . escapeshellarg($dest));
    }
}

$copied = 0;

/** @param list<string> $relDirs */
function copyDir(string $src, string $destRel, string $root, string $base, array $relDirs = []): int
{
    if (!is_dir($src)) {
        fwrite(STDERR, "Warning: missing {$destRel}\n");
        return 0;
    }
    $count = 0;
    foreach (
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        ) as $file
    ) {
        $from = (string) $file;
        $to = $base . '/' . $destRel . '/' . substr($from, strlen($src) + 1);
        $toDir = dirname($to);
        if (!is_dir($toDir)) {
            mkdir($toDir, 0777, true);
        }
        copy($from, $to);
        $count++;
    }
    return $count;
}

function copyFile(string $from, ?string $as = null): int
{
    global $dest, $root;
    if (!is_file($from)) {
        fwrite(STDERR, "Warning: missing {$from}\n");
        return 0;
    }
    $to = $dest . '/' . ($as ?? basename($from));
    $toDir = dirname($to);
    if (!is_dir($toDir)) {
        mkdir($toDir, 0777, true);
    }
    copy($from, $to);
    return 1;
}

if (!is_dir($dest)) {
    mkdir($dest, 0777, true);
}

$copied += copyFile($root . '/index.php');
$copied += copyFile($root . '/.htaccess');
$copied += copyFile($root . '/.env.example');
$copied += copyFile($root . '/docs/CONSUMERS.md', 'docs/CONSUMERS.md');

foreach ($dirs as $rel) {
    $copied += copyDir($root . '/' . $rel, $rel, $root, $dest);
}

foreach ($binScripts as $script) {
    $copied += copyFile($script, 'bin/' . basename($script));
}

// Empty writable storage dir (not copied from source — it only exists on deploys).
if (!is_dir($dest . '/storage')) {
    mkdir($dest . '/storage', 0775, true);
}
if (!is_file($dest . '/storage/.gitkeep')) {
    touch($dest . '/storage/.gitkeep');
}

echo "Exported {$copied} files to prod/jelite_sms_api\n";
echo "Next: upload the CONTENTS of prod/jelite_sms_api to Hostinger public_html,\n";
echo "create .env from .env.example on the server, then bash set-permissions.sh.\n";
