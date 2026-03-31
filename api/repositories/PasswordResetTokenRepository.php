<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class PasswordResetTokenRepository
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
                token,
                expires_at,
                used_at,
                created_at
            FROM password_reset_tokens
            WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                prt.id,
                prt.user_id,
                prt.token,
                prt.expires_at,
                prt.used_at,
                prt.created_at,
                la.email AS user_email
            FROM password_reset_tokens prt
            INNER JOIN login_accounts la ON prt.user_id = la.id
            WHERE prt.token = :token
        ');
        $stmt->execute(['token' => $token]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findValidToken(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                prt.id,
                prt.user_id,
                prt.token,
                prt.expires_at,
                prt.used_at,
                prt.created_at,
                la.email AS user_email
            FROM password_reset_tokens prt
            INNER JOIN login_accounts la ON prt.user_id = la.id
            WHERE prt.token = :token
              AND prt.used_at IS NULL
              AND prt.expires_at > NOW()
        ');
        $stmt->execute(['token' => $token]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                user_id,
                token,
                expires_at,
                used_at,
                created_at
            FROM password_reset_tokens
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO password_reset_tokens (
                user_id,
                token,
                expires_at,
                created_at
            ) VALUES (
                :user_id,
                :token,
                :expires_at,
                NOW()
            )
        ');

        $stmt->execute([
            'user_id' => $data['user_id'],
            'token' => $data['token'],
            'expires_at' => $data['expires_at']
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markAsUsed(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE id = :id AND used_at IS NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function markAsUsedByToken(string $token): bool
    {
        $stmt = $this->db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE token = :token AND used_at IS NULL
        ');
        $stmt->execute(['token' => $token]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByUserId(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function deleteExpired(): int
    {
        $stmt = $this->db->query('
            DELETE FROM password_reset_tokens
            WHERE expires_at < NOW() OR used_at IS NOT NULL
        ');

        return $stmt->rowCount();
    }

    public function invalidatePreviousTokens(int $userId): int
    {
        $stmt = $this->db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id AND used_at IS NULL
        ');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function hasValidToken(int $userId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM password_reset_tokens
            WHERE user_id = :user_id
              AND used_at IS NULL
              AND expires_at > NOW()
        ');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function countRecentTokens(int $userId, int $minutes = 60): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM password_reset_tokens
            WHERE user_id = :user_id
              AND created_at > DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
        ');
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
