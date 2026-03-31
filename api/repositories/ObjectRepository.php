<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ObjectRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT o.*, i.ico, i.name as ico_name
            FROM objects o
            JOIN icos i ON o.ico_id = i.id
            WHERE o.id = :id
        ');

        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByIcoId(int $icoId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM objects
            WHERE ico_id = :ico_id
            ORDER BY name ASC
        ');

        $stmt->execute(['ico_id' => $icoId]);

        return $stmt->fetchAll();
    }

    public function findByIco(string $ico): array
    {
        $stmt = $this->db->prepare('
            SELECT o.*
            FROM objects o
            JOIN icos i ON o.ico_id = i.id
            WHERE i.ico = :ico
            ORDER BY o.name ASC
        ');

        $stmt->execute(['ico' => $ico]);

        return $stmt->fetchAll();
    }

    public function findByClientId(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT o.*, i.ico, i.name as ico_name
            FROM objects o
            JOIN icos i ON o.ico_id = i.id
            WHERE i.client_id = :client_id
            ORDER BY i.name ASC, o.name ASC
        ');

        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    public function findAll(int $offset = 0, int $limit = 20, ?string $search = null): array
    {
        $params = [];
        $where = '';

        if ($search) {
            $where = 'WHERE o.name LIKE :search OR o.address LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT o.*, i.ico, i.name as ico_name
            FROM objects o
            JOIN icos i ON o.ico_id = i.id
            {$where}
            ORDER BY o.name ASC
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
            $where = 'WHERE name LIKE :search OR address LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM objects {$where}");

        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO objects (
                ico_id, name, address, latitude, longitude,
                created_at, updated_at
            )
            VALUES (
                :ico_id, :name, :address, :latitude, :longitude,
                NOW(), NOW()
            )
        ');

        $stmt->execute([
            'ico_id' => $data['ico_id'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['ico_id', 'name', 'address', 'latitude', 'longitude'];

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

        $sql = 'UPDATE objects SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM objects WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
