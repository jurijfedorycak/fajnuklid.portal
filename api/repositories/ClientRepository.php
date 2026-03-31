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
            SELECT * FROM clients WHERE id = :id
        ');

        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findAll(int $offset = 0, int $limit = 20, ?string $search = null): array
    {
        $params = [];
        $where = '';

        if ($search) {
            $where = 'WHERE display_name LIKE :search OR client_id LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT * FROM clients
            {$where}
            ORDER BY display_name ASC
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
            $where = 'WHERE display_name LIKE :search OR client_id LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM clients {$where}");

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO clients (client_id, display_name, active, created_at, updated_at)
            VALUES (:client_id, :display_name, :active, NOW(), NOW())
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'display_name' => $data['display_name'],
            'active' => $data['active'] ?? true
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['client_id', 'display_name', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE clients SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
