<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class ClientEmployeeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findByClientId(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                ce.id,
                ce.client_id,
                ce.employee_id,
                ce.created_at,
                e.first_name,
                e.last_name,
                e.position,
                e.phone,
                e.email,
                e.photo_url,
                e.tenure_text,
                e.bio,
                e.hobbies,
                e.show_name,
                e.show_photo,
                e.show_phone,
                e.show_email,
                e.show_in_portal,
                e.show_role,
                e.show_hobbies,
                e.show_tenure,
                e.show_bio
            FROM client_employees ce
            INNER JOIN employees e ON ce.employee_id = e.id
            WHERE ce.client_id = :client_id AND e.deleted_at IS NULL
            ORDER BY e.last_name ASC, e.first_name ASC
        ');
        $stmt->execute(['client_id' => $clientId]);

        return $stmt->fetchAll();
    }

    public function findByEmployeeId(int $employeeId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                ce.id,
                ce.client_id,
                ce.employee_id,
                ce.created_at,
                c.client_id AS client_code,
                c.display_name AS client_name
            FROM client_employees ce
            INNER JOIN clients c ON ce.client_id = c.id
            WHERE ce.employee_id = :employee_id AND c.deleted_at IS NULL
            ORDER BY c.display_name ASC
        ');
        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->fetchAll();
    }

    public function exists(int $clientId, int $employeeId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM client_employees
            WHERE client_id = :client_id AND employee_id = :employee_id
        ');
        $stmt->execute([
            'client_id' => $clientId,
            'employee_id' => $employeeId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $clientId, int $employeeId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO client_employees (client_id, employee_id)
            VALUES (:client_id, :employee_id)
        ');

        $stmt->execute([
            'client_id' => $clientId,
            'employee_id' => $employeeId
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM client_employees WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByClientAndEmployee(int $clientId, int $employeeId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM client_employees
            WHERE client_id = :client_id AND employee_id = :employee_id
        ');
        $stmt->execute([
            'client_id' => $clientId,
            'employee_id' => $employeeId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByClientId(int $clientId): int
    {
        $stmt = $this->db->prepare('DELETE FROM client_employees WHERE client_id = :client_id');
        $stmt->execute(['client_id' => $clientId]);

        return $stmt->rowCount();
    }

    public function deleteByEmployeeId(int $employeeId): int
    {
        $stmt = $this->db->prepare('DELETE FROM client_employees WHERE employee_id = :employee_id');
        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->rowCount();
    }

    public function syncClientEmployees(int $clientId, array $employeeIds): void
    {
        $inTransaction = $this->db->inTransaction();

        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->deleteByClientId($clientId);

            $stmt = $this->db->prepare('
                INSERT INTO client_employees (client_id, employee_id)
                VALUES (:client_id, :employee_id)
            ');

            foreach ($employeeIds as $employeeId) {
                if (!is_int($employeeId) || $employeeId <= 0) {
                    throw new \InvalidArgumentException('Invalid employee ID provided');
                }
                $stmt->execute([
                    'client_id' => $clientId,
                    'employee_id' => $employeeId
                ]);
            }

            if (!$inTransaction) {
                $this->db->commit();
            }
        } catch (\Exception $e) {
            if (!$inTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getEmployeeIdsByClient(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT employee_id
            FROM client_employees
            WHERE client_id = :client_id
        ');
        $stmt->execute(['client_id' => $clientId]);

        return array_column($stmt->fetchAll(), 'employee_id');
    }

    public function getClientIdsByEmployee(int $employeeId): array
    {
        $stmt = $this->db->prepare('
            SELECT client_id
            FROM client_employees
            WHERE employee_id = :employee_id
        ');
        $stmt->execute(['employee_id' => $employeeId]);

        return array_column($stmt->fetchAll(), 'client_id');
    }
}
