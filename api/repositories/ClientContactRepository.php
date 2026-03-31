<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ClientContactRepository
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
                created_at,
                updated_at
            FROM client_contacts
            WHERE id = :id
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
                created_at,
                updated_at
            FROM client_contacts
            ORDER BY name ASC
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
                created_at,
                updated_at
            FROM client_contacts
            WHERE 1=1
        ';

        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (name LIKE :search OR position LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY name ASC LIMIT :limit OFFSET :offset';

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
        $sql = 'SELECT COUNT(*) FROM client_contacts WHERE 1=1';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (name LIKE :search OR position LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                cc.id,
                cc.name,
                cc.position,
                cc.phone,
                cc.email,
                cc.created_at,
                cc.updated_at,
                coc.is_primary
            FROM client_contacts cc
            INNER JOIN company_contacts coc ON cc.id = coc.contact_id
            WHERE coc.company_id = :company_id
            ORDER BY coc.is_primary DESC, cc.name ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO client_contacts (
                name,
                position,
                phone,
                email,
                created_at,
                updated_at
            ) VALUES (
                :name,
                :position,
                :phone,
                :email,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'name' => $data['name'],
            'position' => $data['position'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['name', 'position', 'phone', 'email'];

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

        $sql = 'UPDATE client_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM client_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsByEmail(string $email, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM client_contacts WHERE email = :email';
        $params = ['email' => $email];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function findUnassigned(): array
    {
        $stmt = $this->db->query('
            SELECT
                cc.id,
                cc.name,
                cc.position,
                cc.phone,
                cc.email,
                cc.created_at,
                cc.updated_at
            FROM client_contacts cc
            LEFT JOIN company_contacts coc ON cc.id = coc.contact_id
            WHERE coc.contact_id IS NULL
            ORDER BY cc.name ASC
        ');

        return $stmt->fetchAll();
    }
}
