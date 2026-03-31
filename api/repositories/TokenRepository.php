<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class TokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function createPasswordResetToken(int $userId, string $token, int $expiresInMinutes = 60): bool
    {
        // Delete any existing tokens for this user
        $this->deleteByUserId($userId);

        $stmt = $this->db->prepare('
            INSERT INTO password_reset_tokens (user_id, token, expires_at, created_at)
            VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL :expires MINUTE), NOW())
        ');

        return $stmt->execute([
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires' => $expiresInMinutes
        ]);
    }

    public function findValidToken(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT prt.*, la.email
            FROM password_reset_tokens prt
            JOIN login_accounts la ON prt.user_id = la.id
            WHERE prt.token = :token
              AND prt.expires_at > NOW()
              AND prt.used_at IS NULL
        ');

        $stmt->execute(['token' => hash('sha256', $token)]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function markAsUsed(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute(['id' => $id]);
    }

    public function deleteByUserId(int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        return $stmt->execute(['user_id' => $userId]);
    }

    public function deleteExpired(): int
    {
        $stmt = $this->db->prepare('DELETE FROM password_reset_tokens WHERE expires_at < NOW()');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
