<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ClientRepository
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
                client_id,
                display_name,
                created_at,
                updated_at,
                deleted_at
            FROM clients
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByClientId(string $clientId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                client_id,
                display_name,
                created_at,
                updated_at,
                deleted_at
            FROM clients
            WHERE client_id = :client_id AND deleted_at IS NULL
        ');
        $stmt->execute(['client_id' => $clientId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT
                id,
                client_id,
                display_name,
                created_at,
                updated_at
            FROM clients
            WHERE deleted_at IS NULL
            ORDER BY display_name ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null): array
    {
        $sql = '
            SELECT
                id,
                client_id,
                display_name,
                created_at,
                updated_at
            FROM clients
            WHERE deleted_at IS NULL
        ';

        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (display_name LIKE :search OR client_id LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY display_name ASC LIMIT :limit OFFSET :offset';

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
        $sql = 'SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL';
        $params = [];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (display_name LIKE :search OR client_id LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO clients (client_id, display_name, created_at, updated_at)
            VALUES (:client_id, :display_name, NOW(), NOW())
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'display_name' => $data['display_name']
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        if (isset($data['client_id'])) {
            $fields[] = 'client_id = :client_id';
            $params['client_id'] = $data['client_id'];
        }

        if (isset($data['display_name'])) {
            $fields[] = 'display_name = :display_name';
            $params['display_name'] = $data['display_name'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE clients SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE clients
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function restore(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE clients
            SET deleted_at = NULL, updated_at = NOW()
            WHERE id = :id AND deleted_at IS NOT NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsByClientId(string $clientId, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clients WHERE client_id = :client_id AND deleted_at IS NULL';
        $params = ['client_id' => $clientId];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }
}
