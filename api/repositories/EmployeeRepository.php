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
        $stmt = $this->db->prepare('SELECT * FROM employees WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findAll(int $offset = 0, int $limit = 20, ?string $search = null): array
    {
        $params = [];
        $where = '';

        if ($search) {
            $where = 'WHERE first_name LIKE :search OR last_name LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT * FROM employees
            {$where}
            ORDER BY last_name ASC, first_name ASC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(?string $search = null): int
    {
        $params = [];
        $where = '';

        if ($search) {
            $where = 'WHERE first_name LIKE :search OR last_name LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM employees {$where}");

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findByObjectId(int $objectId, bool $applyGdpr = true): array
    {
        $select = $applyGdpr
            ? 'e.id,
               IF(e.show_name, e.first_name, NULL) as first_name,
               IF(e.show_name, e.last_name, NULL) as last_name,
               IF(e.show_photo, e.photo_url, NULL) as photo_url,
               IF(e.show_phone, e.phone, NULL) as phone,
               IF(e.show_email, e.email, NULL) as email,
               e.position'
            : 'e.*';

        $stmt = $this->db->prepare("
            SELECT {$select}
            FROM employees e
            JOIN employee_object_assignments eoa ON e.id = eoa.employee_id
            WHERE eoa.object_id = :object_id
              AND e.active = 1
            ORDER BY e.last_name ASC, e.first_name ASC
        ");

        $stmt->execute(['object_id' => $objectId]);

        return $stmt->fetchAll();
    }

    public function findByIco(string $ico, bool $applyGdpr = true): array
    {
        $select = $applyGdpr
            ? 'e.id,
               IF(e.show_name, e.first_name, NULL) as first_name,
               IF(e.show_name, e.last_name, NULL) as last_name,
               IF(e.show_photo, e.photo_url, NULL) as photo_url,
               IF(e.show_phone, e.phone, NULL) as phone,
               IF(e.show_email, e.email, NULL) as email,
               e.position,
               o.id as object_id,
               o.name as object_name'
            : 'e.*, o.id as object_id, o.name as object_name';

        $stmt = $this->db->prepare("
            SELECT {$select}
            FROM employees e
            JOIN employee_object_assignments eoa ON e.id = eoa.employee_id
            JOIN objects o ON eoa.object_id = o.id
            JOIN icos i ON o.ico_id = i.id
            WHERE i.ico = :ico
              AND e.active = 1
            ORDER BY o.name ASC, e.last_name ASC, e.first_name ASC
        ");

        $stmt->execute(['ico' => $ico]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO employees (
                first_name, last_name, email, phone, position, photo_url,
                show_name, show_photo, show_phone, show_email,
                active, created_at, updated_at
            )
            VALUES (
                :first_name, :last_name, :email, :phone, :position, :photo_url,
                :show_name, :show_photo, :show_phone, :show_email,
                :active, NOW(), NOW()
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
            'show_email' => $data['show_email'] ?? false,
            'active' => $data['active'] ?? true
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'first_name', 'last_name', 'email', 'phone', 'position', 'photo_url',
            'show_name', 'show_photo', 'show_phone', 'show_email', 'active'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE employees SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM employees WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function assignToObject(int $employeeId, int $objectId): bool
    {
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO employee_object_assignments (employee_id, object_id, created_at)
            VALUES (:employee_id, :object_id, NOW())
        ');

        return $stmt->execute([
            'employee_id' => $employeeId,
            'object_id' => $objectId
        ]);
    }

    public function unassignFromObject(int $employeeId, int $objectId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM employee_object_assignments
            WHERE employee_id = :employee_id AND object_id = :object_id
        ');

        return $stmt->execute([
            'employee_id' => $employeeId,
            'object_id' => $objectId
        ]);
    }

    public function getAssignedObjects(int $employeeId): array
    {
        $stmt = $this->db->prepare('
            SELECT o.*
            FROM objects o
            JOIN employee_object_assignments eoa ON o.id = eoa.object_id
            WHERE eoa.employee_id = :employee_id
            ORDER BY o.name ASC
        ');

        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->fetchAll();
    }
}
