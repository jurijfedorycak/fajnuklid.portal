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
                tenure_text,
                bio,
                hobbies,
                contract_file,
                show_name,
                show_photo,
                show_phone,
                show_email,
                show_in_portal,
                show_role,
                show_hobbies,
                show_tenure,
                show_bio,
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
                tenure_text,
                bio,
                hobbies,
                contract_file,
                show_name,
                show_photo,
                show_phone,
                show_email,
                show_in_portal,
                show_role,
                show_hobbies,
                show_tenure,
                show_bio,
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
                tenure_text,
                bio,
                hobbies,
                contract_file,
                show_name,
                show_photo,
                show_phone,
                show_email,
                show_in_portal,
                show_role,
                show_hobbies,
                show_tenure,
                show_bio,
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
                e.tenure_text,
                e.bio,
                e.hobbies,
                e.contract_file,
                e.show_name,
                e.show_photo,
                e.show_phone,
                e.show_email,
                e.show_in_portal,
                e.show_role,
                e.show_hobbies,
                e.show_tenure,
                e.show_bio
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
                tenure_text,
                bio,
                hobbies,
                contract_file,
                show_name,
                show_photo,
                show_phone,
                show_email,
                show_in_portal,
                show_role,
                show_hobbies,
                show_tenure,
                show_bio,
                created_at,
                updated_at
            ) VALUES (
                :first_name,
                :last_name,
                :email,
                :phone,
                :position,
                :photo_url,
                :tenure_text,
                :bio,
                :hobbies,
                :contract_file,
                :show_name,
                :show_photo,
                :show_phone,
                :show_email,
                :show_in_portal,
                :show_role,
                :show_hobbies,
                :show_tenure,
                :show_bio,
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
            'tenure_text' => $data['tenure_text'] ?? null,
            'bio' => $data['bio'] ?? null,
            'hobbies' => $data['hobbies'] ?? null,
            'contract_file' => $data['contract_file'] ?? null,
            'show_name' => (int) ($data['show_name'] ?? true),
            'show_photo' => (int) ($data['show_photo'] ?? true),
            'show_phone' => (int) ($data['show_phone'] ?? false),
            'show_email' => (int) ($data['show_email'] ?? false),
            'show_in_portal' => (int) ($data['show_in_portal'] ?? false),
            'show_role' => (int) ($data['show_role'] ?? true),
            'show_hobbies' => (int) ($data['show_hobbies'] ?? false),
            'show_tenure' => (int) ($data['show_tenure'] ?? true),
            'show_bio' => (int) ($data['show_bio'] ?? false),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'first_name', 'last_name', 'email', 'phone', 'position',
            'photo_url', 'tenure_text', 'bio', 'hobbies', 'contract_file',
            'show_name', 'show_photo', 'show_phone', 'show_email',
            'show_in_portal', 'show_role', 'show_hobbies', 'show_tenure', 'show_bio'
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

    /**
     * Bulk save employees (insert or update) within a transaction.
     *
     * @param array $employees Array of employee data with optional 'id' field
     * @return array Array of saved employee IDs
     * @throws \Exception If any operation fails (transaction is rolled back)
     */
    public function saveAll(array $employees): array
    {
        $savedIds = [];

        $this->db->beginTransaction();
        try {
            foreach ($employees as $data) {
                if (isset($data['id']) && $data['id'] > 0) {
                    // Update existing
                    $this->update((int) $data['id'], $data);
                    $savedIds[] = (int) $data['id'];
                } else {
                    // Create new
                    $savedIds[] = $this->create($data);
                }
            }
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $savedIds;
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
