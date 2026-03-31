<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class EmployeeRepository
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
                first_name,
                last_name,
                email,
                phone,
                position,
                photo_url,
                show_name,
                show_photo,
                show_phone,
                show_email,
                created_at,
                updated_at,
                deleted_at
            FROM employees
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT
                id,
                first_name,
                last_name,
                email,
                phone,
                position,
                photo_url,
                show_name,
                show_photo,
                show_phone,
                show_email,
                created_at,
                updated_at
            FROM employees
            WHERE deleted_at IS NULL
            ORDER BY last_name ASC, first_name ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null): array
    {
        $sql = '
            SELECT
                id,
                first_name,
                last_name,
                email,
                phone,
                position,
                photo_url,
                show_name,
                show_photo,
                show_phone,
                show_email,
                created_at,
                updated_at
            FROM employees
            WHERE deleted_at IS NULL
        ';

        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (
                first_name LIKE :search
                OR last_name LIKE :search
                OR email LIKE :search
                OR position LIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY last_name ASC, first_name ASC LIMIT :limit OFFSET :offset';

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
        $sql = 'SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (
                first_name LIKE :search
                OR last_name LIKE :search
                OR email LIKE :search
                OR position LIKE :search
            )';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findByLocation(int $locationId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                e.id,
                e.first_name,
                e.last_name,
                e.email,
                e.phone,
                e.position,
                e.photo_url,
                e.show_name,
                e.show_photo,
                e.show_phone,
                e.show_email
            FROM employees e
            INNER JOIN employee_locations el ON e.id = el.employee_id
            WHERE el.location_id = :location_id AND e.deleted_at IS NULL
            ORDER BY e.last_name ASC, e.first_name ASC
        ');
        $stmt->execute(['location_id' => $locationId]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO employees (
                first_name,
                last_name,
                email,
                phone,
                position,
                photo_url,
                show_name,
                show_photo,
                show_phone,
                show_email,
                created_at,
                updated_at
            ) VALUES (
                :first_name,
                :last_name,
                :email,
                :phone,
                :position,
                :photo_url,
                :show_name,
                :show_photo,
                :show_phone,
                :show_email,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'show_name' => $data['show_name'] ?? true,
            'show_photo' => $data['show_photo'] ?? true,
            'show_phone' => $data['show_phone'] ?? false,
            'show_email' => $data['show_email'] ?? false
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'first_name', 'last_name', 'email', 'phone', 'position',
            'photo_url', 'show_name', 'show_photo', 'show_phone', 'show_email'
        ];

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

        $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE employees
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE employees
            SET deleted_at = NULL, updated_at = NOW()
            WHERE id = :id AND deleted_at IS NOT NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsByEmail(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM employees WHERE email = :email AND deleted_at IS NULL';
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
