<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class AppSettingRepository
{
    public const KEY_GOOGLE_REVIEW_URL = 'google_review_url';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        // fetchColumn() returns false when the row is missing and null when the
        // column itself is NULL — both mean "not configured" to callers.
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
            VALUES (:key, :value, NOW(), NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ');
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}
