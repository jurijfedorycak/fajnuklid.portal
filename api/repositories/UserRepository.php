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
                portal_enabled,
                is_admin,
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
                portal_enabled,
                is_admin,
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

    public function updatePassword(int $userId, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('
            UPDATE login_accounts
            SET password_hash = :password_hash, updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT
                id,
                email,
                portal_enabled,
                is_admin,
                created_at,
                updated_at
            FROM login_accounts
            ORDER BY email ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null): array
    {
        $sql = '
            SELECT
                id,
                email,
                portal_enabled,
                is_admin,
                created_at,
                updated_at
            FROM login_accounts
            WHERE 1=1
        ';

        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND email LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY email ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(?string $search = null): int
    {
        $sql = 'SELECT COUNT(*) FROM login_accounts WHERE 1=1';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND email LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO login_accounts (email, password_hash, portal_enabled, created_at, updated_at)
            VALUES (:email, :password_hash, :portal_enabled, NOW(), NOW())
        ');

        $stmt->execute([
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'portal_enabled' => $data['portal_enabled'] ?? true,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['email'])) {
            $fields[] = 'email = :email';
            $params['email'] = $data['email'];
        }

        if (isset($data['password_hash'])) {
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = $data['password_hash'];
        }

        if (array_key_exists('portal_enabled', $data)) {
            $fields[] = 'portal_enabled = :portal_enabled';
            $params['portal_enabled'] = (int) (bool) $data['portal_enabled'];
        }

        if (array_key_exists('is_admin', $data)) {
            $fields[] = 'is_admin = :is_admin';
            $params['is_admin'] = (int) (bool) $data['is_admin'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE login_accounts SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM login_accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function countActiveAdmins(): int
    {
        $stmt = $this->db->query('
            SELECT COUNT(*) FROM login_accounts
            WHERE is_admin = 1 AND portal_enabled = 1
        ');

        return (int) $stmt->fetchColumn();
    }

    public function existsByEmail(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM login_accounts WHERE email = :email';
        $params = ['email' => $email];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }
}
