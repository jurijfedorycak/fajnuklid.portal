<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class UserSettingsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                user_id,
                notification_email,
                notification_invoice,
                notification_attendance,
                created_at,
                updated_at
            FROM user_settings
            WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                user_id,
                notification_email,
                notification_invoice,
                notification_attendance,
                created_at,
                updated_at
            FROM user_settings
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findOrCreate(int $userId): array
    {
        $settings = $this->findByUserId($userId);

        if ($settings !== null) {
            return $settings;
        }

        $this->create([
            'user_id' => $userId,
            'notification_email' => true,
            'notification_invoice' => true,
            'notification_attendance' => true
        ]);

        $settings = $this->findByUserId($userId);

        if ($settings === null) {
            throw new \RuntimeException("Failed to create user settings for user ID: {$userId}");
        }

        return $settings;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO user_settings (
                user_id,
                notification_email,
                notification_invoice,
                notification_attendance,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :notification_email,
                :notification_invoice,
                :notification_attendance,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'user_id' => $data['user_id'],
            'notification_email' => $data['notification_email'] ?? true,
            'notification_invoice' => $data['notification_invoice'] ?? true,
            'notification_attendance' => $data['notification_attendance'] ?? true
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['notification_email', 'notification_invoice', 'notification_attendance'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE user_settings SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function updateByUserId(int $userId, array $data): bool
    {
        $fields = [];
        $params = ['user_id' => $userId];

        $allowedFields = ['notification_email', 'notification_invoice', 'notification_attendance'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE user_settings SET ' . implode(', ', $fields) . ' WHERE user_id = :user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_settings WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByUserId(int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_settings WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function findUsersWithNotificationEnabled(string $notificationType): array
    {
        $columnMap = [
            'notification_email' => 'notification_email',
            'notification_invoice' => 'notification_invoice',
            'notification_attendance' => 'notification_attendance'
        ];

        if (!isset($columnMap[$notificationType])) {
            return [];
        }

        $column = $columnMap[$notificationType];

        $stmt = $this->db->prepare("
            SELECT
                us.id,
                us.user_id,
                la.email
            FROM user_settings us
            INNER JOIN login_accounts la ON us.user_id = la.id
            WHERE us.{$column} = 1 AND la.portal_enabled = 1
        ");
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
