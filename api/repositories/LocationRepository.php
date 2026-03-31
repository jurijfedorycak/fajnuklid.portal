<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class LocationRepository
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
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                l.created_at,
                l.updated_at,
                c.name AS company_name,
                c.registration_number AS company_registration_number
            FROM locations l
            LEFT JOIN companies c ON l.company_id = c.id
            WHERE l.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                company_id,
                name,
                address,
                latitude,
                longitude,
                created_at,
                updated_at
            FROM locations
            WHERE company_id = :company_id
            ORDER BY name ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                l.created_at,
                l.updated_at,
                c.name AS company_name
            FROM locations l
            LEFT JOIN companies c ON l.company_id = c.id
            ORDER BY l.name ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null, ?int $companyId = null): array
    {
        $sql = '
            SELECT
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                l.created_at,
                l.updated_at,
                c.name AS company_name
            FROM locations l
            LEFT JOIN companies c ON l.company_id = c.id
            WHERE 1=1
        ';

        $params = [];

        if ($companyId !== null) {
            $sql .= ' AND l.company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (l.name LIKE :search OR l.address LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY l.name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(?string $search = null, ?int $companyId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM locations l WHERE 1=1';
        $params = [];

        if ($companyId !== null) {
            $sql .= ' AND l.company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (l.name LIKE :search OR l.address LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findByClientId(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                l.created_at,
                l.updated_at,
                c.name AS company_name
            FROM locations l
            INNER JOIN companies c ON l.company_id = c.id
            WHERE c.client_id = :client_id
            ORDER BY c.name ASC, l.name ASC
        ');
        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                l.created_at,
                l.updated_at,
                c.name AS company_name
            FROM locations l
            INNER JOIN companies c ON l.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
            ORDER BY c.name ASC, l.name ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO locations (
                company_id,
                name,
                address,
                latitude,
                longitude,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :name,
                :address,
                :latitude,
                :longitude,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'company_id' => $data['company_id'],
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

        $allowedFields = ['company_id', 'name', 'address', 'latitude', 'longitude'];

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

        $sql = 'UPDATE locations SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM locations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function findWithCoordinates(): array
    {
        $stmt = $this->db->query('
            SELECT
                l.id,
                l.company_id,
                l.name,
                l.address,
                l.latitude,
                l.longitude,
                c.name AS company_name
            FROM locations l
            LEFT JOIN companies c ON l.company_id = c.id
            WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL
            ORDER BY l.name ASC
        ');

        return $stmt->fetchAll();
    }

    public function belongsToUser(int $locationId, int $userId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM locations l
            INNER JOIN companies c ON l.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE l.id = :location_id AND cu.user_id = :user_id
        ');
        $stmt->execute([
            'location_id' => $locationId,
            'user_id' => $userId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
