<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class IcoRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM icos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByClientId(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM icos
            WHERE client_id = :client_id
            ORDER BY name ASC
        ');

        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    public function findByIco(string $ico): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM icos WHERE ico = :ico');
        $stmt->execute(['ico' => $ico]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO icos (
                client_id, ico, name, address,
                contract_start_date, contract_end_date, contract_pdf_path,
                created_at, updated_at
            )
            VALUES (
                :client_id, :ico, :name, :address,
                :contract_start_date, :contract_end_date, :contract_pdf_path,
                NOW(), NOW()
            )
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'ico' => $data['ico'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'contract_start_date' => $data['contract_start_date'] ?? null,
            'contract_end_date' => $data['contract_end_date'] ?? null,
            'contract_pdf_path' => $data['contract_pdf_path'] ?? null
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'ico', 'name', 'address',
            'contract_start_date', 'contract_end_date', 'contract_pdf_path'
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

        $sql = 'UPDATE icos SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM icos WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }
}
