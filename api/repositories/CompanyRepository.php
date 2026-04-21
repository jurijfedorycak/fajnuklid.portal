<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class CompanyRepository
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
                c.id,
                c.client_id,
                c.registration_number,
                c.name,
                c.address,
                c.contract_start_date,
                c.contract_end_date,
                c.contract_pdf_path,
                c.idoklad_sync_enabled,
                c.created_at,
                c.updated_at,
                cl.display_name AS client_name
            FROM companies c
            LEFT JOIN clients cl ON c.client_id = cl.id AND cl.deleted_at IS NULL
            WHERE c.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByRegistrationNumber(string $registrationNumber): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                c.id,
                c.client_id,
                c.registration_number,
                c.name,
                c.address,
                c.contract_start_date,
                c.contract_end_date,
                c.contract_pdf_path,
                c.idoklad_sync_enabled,
                c.created_at,
                c.updated_at
            FROM companies c
            WHERE c.registration_number = :registration_number
        ');
        $stmt->execute(['registration_number' => $registrationNumber]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByClientId(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                client_id,
                registration_number,
                name,
                address,
                contract_start_date,
                contract_end_date,
                contract_pdf_path,
                idoklad_sync_enabled,
                created_at,
                updated_at
            FROM companies
            WHERE client_id = :client_id
            ORDER BY name ASC
        ');
        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('
            SELECT
                c.id,
                c.client_id,
                c.registration_number,
                c.name,
                c.address,
                c.contract_start_date,
                c.contract_end_date,
                c.contract_pdf_path,
                c.idoklad_sync_enabled,
                c.created_at,
                c.updated_at,
                cl.display_name AS client_name
            FROM companies c
            LEFT JOIN clients cl ON c.client_id = cl.id AND cl.deleted_at IS NULL
            ORDER BY c.name ASC
        ');

        return $stmt->fetchAll();
    }

    public function findPaginated(int $limit, int $offset, ?string $search = null, ?int $clientId = null): array
    {
        $sql = '
            SELECT
                c.id,
                c.client_id,
                c.registration_number,
                c.name,
                c.address,
                c.contract_start_date,
                c.contract_end_date,
                c.contract_pdf_path,
                c.idoklad_sync_enabled,
                c.created_at,
                c.updated_at,
                cl.display_name AS client_name
            FROM companies c
            LEFT JOIN clients cl ON c.client_id = cl.id AND cl.deleted_at IS NULL
            WHERE 1=1
        ';

        $params = [];

        if ($clientId !== null) {
            $sql .= ' AND c.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (c.name LIKE :search OR c.registration_number LIKE :search OR c.address LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY c.name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countAll(?string $search = null, ?int $clientId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM companies c WHERE 1=1';
        $params = [];

        if ($clientId !== null) {
            $sql .= ' AND c.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        if ($search !== null && $search !== '') {
            $sql .= ' AND (c.name LIKE :search OR c.registration_number LIKE :search OR c.address LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO companies (
                client_id,
                registration_number,
                name,
                address,
                contract_start_date,
                contract_end_date,
                contract_pdf_path,
                idoklad_sync_enabled,
                created_at,
                updated_at
            ) VALUES (
                :client_id,
                :registration_number,
                :name,
                :address,
                :contract_start_date,
                :contract_end_date,
                :contract_pdf_path,
                :idoklad_sync_enabled,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'registration_number' => $data['registration_number'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'contract_start_date' => $data['contract_start_date'] ?? null,
            'contract_end_date' => $data['contract_end_date'] ?? null,
            'contract_pdf_path' => $data['contract_pdf_path'] ?? null,
            'idoklad_sync_enabled' => (int) (bool) ($data['idoklad_sync_enabled'] ?? false),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'client_id', 'registration_number', 'name', 'address',
            'contract_start_date', 'contract_end_date', 'contract_pdf_path',
            'idoklad_sync_enabled',
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

        $sql = 'UPDATE companies SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM companies WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function existsByRegistrationNumber(string $registrationNumber, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM companies WHERE registration_number = :registration_number';
        $params = ['registration_number' => $registrationNumber];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                c.id,
                c.client_id,
                c.registration_number,
                c.name,
                c.address,
                c.contract_start_date,
                c.contract_end_date,
                c.contract_pdf_path,
                c.idoklad_sync_enabled,
                c.created_at,
                c.updated_at
            FROM companies c
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
            ORDER BY c.name ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findAllWithIdokladSyncEnabled(): array
    {
        $stmt = $this->db->query("
            SELECT
                c.id,
                c.client_id,
                c.registration_number,
                c.name
            FROM companies c
            WHERE c.idoklad_sync_enabled = 1
              AND c.registration_number <> ''
            ORDER BY c.name ASC
        ");

        return $stmt->fetchAll();
    }

    public function hasActiveContract(int $id): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM companies
            WHERE id = :id
              AND contract_start_date IS NOT NULL
              AND (contract_end_date IS NULL OR contract_end_date >= CURDATE())
        ');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
