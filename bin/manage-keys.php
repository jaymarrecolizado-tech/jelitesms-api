<?php

declare(strict_types=1);

// API key management CLI.
//
//   php bin/manage-keys.php create --name="HRMIS" --rate=30
//   php bin/manage-keys.php list
//   php bin/manage-keys.php revoke --id=2

require dirname(__DIR__) . '/src/autoload.php';

use Jelite\ApiKeyRepository;
use Jelite\Config;
use Jelite\Database;

Config::load(dirname(__DIR__) . '/.env');

$cmd = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z_]+)=(.*)$/i', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

$repo = new ApiKeyRepository(Database::pdo());

switch ($cmd) {
    case 'create':
        $name = $options['name'] ?? '';
        if ($name === '') {
            fwrite(STDERR, "Usage: manage-keys.php create --name=\"Consumer name\" [--rate=30]\n");
            exit(1);
        }
        $key = $repo->create($name, (int) ($options['rate'] ?? 30));
        echo "API key created for \"{$key['name']}\" (id={$key['id']}, prefix={$key['prefix']})\n";
        echo "KEY (store it now — only the hash is kept): {$key['key']}\n";
        break;

    case 'list':
        foreach ($repo->list() as $k) {
            printf(
                "#%d %-20s prefix=%s %s rate=%d/min created=%s%s\n",
                $k['id'],
                $k['name'],
                $k['key_prefix'],
                $k['active'] ? 'active' : 'REVOKED',
                $k['rate_limit_per_minute'],
                $k['created_at'],
                $k['revoked_at'] ? " revoked={$k['revoked_at']}" : ''
            );
        }
        break;

    case 'revoke':
        if (!isset($options['id'])) {
            fwrite(STDERR, "Usage: manage-keys.php revoke --id=2\n");
            exit(1);
        }
        echo $repo->revoke((int) $options['id']) ? "Key revoked.\n" : "Key not found or already revoked.\n";
        break;

    default:
        fwrite(STDERR, "Usage: manage-keys.php {create|list|revoke} [options]\n");
        exit(1);
}
