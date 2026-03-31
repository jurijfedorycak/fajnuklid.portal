<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class EmployeeLocationRepository
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
                el.id,
                el.employee_id,
                el.location_id,
                e.first_name AS employee_first_name,
                e.last_name AS employee_last_name,
                l.name AS location_name,
                l.address AS location_address
            FROM employee_locations el
            INNER JOIN employees e ON el.employee_id = e.id
            INNER JOIN locations l ON el.location_id = l.id
            WHERE el.id = :id AND e.deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByEmployeeId(int $employeeId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                el.id,
                el.employee_id,
                el.location_id,
                l.name AS location_name,
                l.address AS location_address,
                c.name AS company_name
            FROM employee_locations el
            INNER JOIN locations l ON el.location_id = l.id
            LEFT JOIN companies c ON l.company_id = c.id
            WHERE el.employee_id = :employee_id
            ORDER BY c.name ASC, l.name ASC
        ');
        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->fetchAll();
    }

    public function findByLocationId(int $locationId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                el.id,
                el.employee_id,
                el.location_id,
                e.first_name,
                e.last_name,
                e.position,
                e.photo_url,
                e.show_name,
                e.show_photo
            FROM employee_locations el
            INNER JOIN employees e ON el.employee_id = e.id
            WHERE el.location_id = :location_id AND e.deleted_at IS NULL
            ORDER BY e.last_name ASC, e.first_name ASC
        ');
        $stmt->execute(['location_id' => $locationId]);

        return $stmt->fetchAll();
    }

    public function exists(int $employeeId, int $locationId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM employee_locations
            WHERE employee_id = :employee_id AND location_id = :location_id
        ');
        $stmt->execute([
            'employee_id' => $employeeId,
            'location_id' => $locationId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $employeeId, int $locationId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO employee_locations (employee_id, location_id)
            VALUES (:employee_id, :location_id)
        ');

        $stmt->execute([
            'employee_id' => $employeeId,
            'location_id' => $locationId
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM employee_locations WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByEmployeeAndLocation(int $employeeId, int $locationId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM employee_locations
            WHERE employee_id = :employee_id AND location_id = :location_id
        ');
        $stmt->execute([
            'employee_id' => $employeeId,
            'location_id' => $locationId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByEmployeeId(int $employeeId): int
    {
        $stmt = $this->db->prepare('DELETE FROM employee_locations WHERE employee_id = :employee_id');
        $stmt->execute(['employee_id' => $employeeId]);

        return $stmt->rowCount();
    }

    public function deleteByLocationId(int $locationId): int
    {
        $stmt = $this->db->prepare('DELETE FROM employee_locations WHERE location_id = :location_id');
        $stmt->execute(['location_id' => $locationId]);

        return $stmt->rowCount();
    }

    public function syncEmployeeLocations(int $employeeId, array $locationIds): void
    {
        $this->db->beginTransaction();

        try {
            $this->deleteByEmployeeId($employeeId);

            $stmt = $this->db->prepare('
                INSERT INTO employee_locations (employee_id, location_id)
                VALUES (:employee_id, :location_id)
            ');

            foreach ($locationIds as $locationId) {
                if (!is_int($locationId) || $locationId <= 0) {
                    throw new \InvalidArgumentException('Invalid location ID provided');
                }
                $stmt->execute([
                    'employee_id' => $employeeId,
                    'location_id' => $locationId
                ]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function syncLocationEmployees(int $locationId, array $employeeIds): void
    {
        $this->db->beginTransaction();

        try {
            $this->deleteByLocationId($locationId);

            $stmt = $this->db->prepare('
                INSERT INTO employee_locations (employee_id, location_id)
                VALUES (:employee_id, :location_id)
            ');

            foreach ($employeeIds as $employeeId) {
                if (!is_int($employeeId) || $employeeId <= 0) {
                    throw new \InvalidArgumentException('Invalid employee ID provided');
                }
                $stmt->execute([
                    'employee_id' => $employeeId,
                    'location_id' => $locationId
                ]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getLocationIdsByEmployee(int $employeeId): array
    {
        $stmt = $this->db->prepare('
            SELECT location_id
            FROM employee_locations
            WHERE employee_id = :employee_id
        ');
        $stmt->execute(['employee_id' => $employeeId]);

        return array_column($stmt->fetchAll(), 'location_id');
    }

    public function getEmployeeIdsByLocation(int $locationId): array
    {
        $stmt = $this->db->prepare('
            SELECT employee_id
            FROM employee_locations
            WHERE location_id = :location_id
        ');
        $stmt->execute(['location_id' => $locationId]);

        return array_column($stmt->fetchAll(), 'employee_id');
    }
}
