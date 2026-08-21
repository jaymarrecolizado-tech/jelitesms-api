<?php

namespace Jelite;

class SettingsRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @return array<string,string> key => value
     */
    public function all(): array
    {
        $rows = $this->db->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
        return is_array($rows) ? $rows : [];
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key, $value]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
    }
}
