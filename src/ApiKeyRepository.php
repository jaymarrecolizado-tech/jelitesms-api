<?php

namespace Jelite;

class ApiKeyRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * Create a key; returns the plaintext key exactly once.
     *
     * @return array{id:int,name:string,key:string,prefix:string}
     */
    public function create(string $name, int $rateLimitPerMinute = 30): array
    {
        $key = 'jl_' . bin2hex(random_bytes(24));
        $prefix = substr($key, 0, 10);
        $hash = self::hash($key);

        $stmt = $this->db->prepare(
            'INSERT INTO api_keys (name, key_hash, key_prefix, rate_limit_per_minute) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $hash, $prefix, max(1, $rateLimitPerMinute)]);

        return ['id' => (int) $this->db->lastInsertId(), 'name' => $name, 'key' => $key, 'prefix' => $prefix];
    }

    public function revoke(int $id): bool
    {
        $stmt = $this->db->prepare('UPDATE api_keys SET active = 0, revoked_at = NOW() WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM api_keys WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array|null api_keys row or null when unknown/revoked
     */
    public function authenticate(string $plaintextKey): ?array
    {
        if ($plaintextKey === '' || strlen($plaintextKey) > 200) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND active = 1');
        $stmt->execute([self::hash($plaintextKey)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array>
     */
    public function list(): array
    {
        return $this->db
            ->query('SELECT id, name, key_prefix, active, rate_limit_per_minute, created_at, revoked_at FROM api_keys ORDER BY id')
            ->fetchAll();
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
