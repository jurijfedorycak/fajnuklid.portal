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
                la.id,
                la.email,
                la.password_hash,
                la.client_id,
                la.portal_enabled,
                la.created_at,
                la.updated_at,
                c.display_name as client_name,
                c.active as client_active
            FROM login_accounts la
            LEFT JOIN clients c ON la.client_id = c.id
            WHERE la.id = :id
        ');

        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                la.id,
                la.email,
                la.password_hash,
                la.client_id,
                la.portal_enabled,
                la.created_at,
                la.updated_at,
                c.display_name as client_name,
                c.active as client_active
            FROM login_accounts la
            LEFT JOIN clients c ON la.client_id = c.id
            WHERE la.email = :email
        ');

        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare('
            UPDATE login_accounts
            SET password_hash = :password_hash, updated_at = NOW()
            WHERE id = :id
        ');

        return $stmt->execute([
            'id' => $id,
            'password_hash' => $passwordHash
        ]);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO login_accounts (email, password_hash, client_id, portal_enabled, created_at, updated_at)
            VALUES (:email, :password_hash, :client_id, :portal_enabled, NOW(), NOW())
        ');

        $stmt->execute([
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'client_id' => $data['client_id'],
            'portal_enabled' => $data['portal_enabled'] ?? true
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['email', 'client_id', 'portal_enabled'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE login_accounts SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM login_accounts WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
