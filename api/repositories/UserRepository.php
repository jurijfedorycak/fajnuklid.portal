<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class UserRepository
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
                email,
                password_hash,
                client_id,
                portal_enabled,
                created_at,
                updated_at
            FROM login_accounts
            WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                email,
                password_hash,
                client_id,
                portal_enabled,
                created_at,
                updated_at
            FROM login_accounts
            WHERE email = :email
        ');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare('
            UPDATE login_accounts
            SET updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute(['id' => $userId]);
    }
}
