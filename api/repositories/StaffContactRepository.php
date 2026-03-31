<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class StaffContactRepository
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
                name,
                position,
                phone,
                email,
                photo_url,
                sort_order,
                created_at,
                updated_at,
                deleted_at
            FROM staff_contacts
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
                name,
                position,
                phone,
                email,
                photo_url,
                sort_order,
                created_at,
                updated_at
            FROM staff_contacts
            WHERE deleted_at IS NULL
            ORDER BY sort_order ASC, name ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null): array
    {
        $sql = '
            SELECT
                id,
                name,
                position,
                phone,
                email,
                photo_url,
                sort_order,
                created_at,
                updated_at
            FROM staff_contacts
            WHERE deleted_at IS NULL
        ';

        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (name LIKE :search OR position LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY sort_order ASC, name ASC LIMIT :limit OFFSET :offset';

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
        $sql = 'SELECT COUNT(*) FROM staff_contacts WHERE deleted_at IS NULL';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (name LIKE :search OR position LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO staff_contacts (
                name,
                position,
                phone,
                email,
                photo_url,
                sort_order,
                created_at,
                updated_at
            ) VALUES (
                :name,
                :position,
                :phone,
                :email,
                :photo_url,
                :sort_order,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'name' => $data['name'],
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['name', 'position', 'phone', 'email', 'photo_url', 'sort_order'];

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

        $sql = 'UPDATE staff_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE staff_contacts
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE staff_contacts
            SET deleted_at = NULL, updated_at = NOW()
            WHERE id = :id AND deleted_at IS NOT NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function getMaxSortOrder(): int
    {
        $stmt = $this->db->query('SELECT MAX(sort_order) FROM staff_contacts WHERE deleted_at IS NULL');
        $result = $stmt->fetchColumn();

        return $result !== false ? (int) $result : 0;
    }

    public function reorder(array $orderedIds): bool
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                UPDATE staff_contacts
                SET sort_order = :sort_order, updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ');

            foreach ($orderedIds as $index => $id) {
                $stmt->execute([
                    'id' => $id,
                    'sort_order' => $index
                ]);
            }

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
