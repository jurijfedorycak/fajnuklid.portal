<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class CompanyUserRepository
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
                cu.id,
                cu.company_id,
                cu.user_id,
                c.name AS company_name,
                c.registration_number,
                la.email AS user_email
            FROM company_users cu
            INNER JOIN companies c ON cu.company_id = c.id
            INNER JOIN login_accounts la ON cu.user_id = la.id
            WHERE cu.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                cu.id,
                cu.company_id,
                cu.user_id,
                la.email AS user_email,
                la.portal_enabled
            FROM company_users cu
            INNER JOIN login_accounts la ON cu.user_id = la.id
            WHERE cu.company_id = :company_id
            ORDER BY la.email ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                cu.id,
                cu.company_id,
                cu.user_id,
                c.name AS company_name,
                c.registration_number,
                c.address AS company_address
            FROM company_users cu
            INNER JOIN companies c ON cu.company_id = c.id
            WHERE cu.user_id = :user_id
            ORDER BY c.name ASC
        ');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function exists(int $companyId, int $userId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM company_users
            WHERE company_id = :company_id AND user_id = :user_id
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $companyId, int $userId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO company_users (company_id, user_id)
            VALUES (:company_id, :user_id)
        ');

        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM company_users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByCompanyAndUser(int $companyId, int $userId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM company_users
            WHERE company_id = :company_id AND user_id = :user_id
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByCompanyId(int $companyId): int
    {
        $stmt = $this->db->prepare('DELETE FROM company_users WHERE company_id = :company_id');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->rowCount();
    }

    public function deleteByUserId(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM company_users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->rowCount();
    }

    public function syncUserCompanies(int $userId, array $companyIds): void
    {
        $inTransaction = $this->db->inTransaction();

        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->deleteByUserId($userId);

            $stmt = $this->db->prepare('
                INSERT INTO company_users (company_id, user_id)
                VALUES (:company_id, :user_id)
            ');

            foreach ($companyIds as $companyId) {
                if (!is_int($companyId) || $companyId <= 0) {
                    throw new \InvalidArgumentException('Invalid company ID provided');
                }
                $stmt->execute([
                    'company_id' => $companyId,
                    'user_id' => $userId
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

    public function syncCompanyUsers(int $companyId, array $userIds): void
    {
        $inTransaction = $this->db->inTransaction();

        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->deleteByCompanyId($companyId);

            $stmt = $this->db->prepare('
                INSERT INTO company_users (company_id, user_id)
                VALUES (:company_id, :user_id)
            ');

            foreach ($userIds as $userId) {
                if (!is_int($userId) || $userId <= 0) {
                    throw new \InvalidArgumentException('Invalid user ID provided');
                }
                $stmt->execute([
                    'company_id' => $companyId,
                    'user_id' => $userId
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

    public function getCompanyIdsByUser(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT company_id
            FROM company_users
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);

        return array_column($stmt->fetchAll(), 'company_id');
    }

    public function getUserIdsByCompany(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT user_id
            FROM company_users
            WHERE company_id = :company_id
        ');
        $stmt->execute(['company_id' => $companyId]);

        return array_column($stmt->fetchAll(), 'user_id');
    }

    public function userHasAccessToCompany(int $userId, int $companyId): bool
    {
        return $this->exists($companyId, $userId);
    }

    public function userHasAccessToLocation(int $userId, int $locationId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM company_users cu
            INNER JOIN locations l ON cu.company_id = l.company_id
            WHERE cu.user_id = :user_id AND l.id = :location_id
        ');
        $stmt->execute([
            'user_id' => $userId,
            'location_id' => $locationId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function countUserCompanies(int $userId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM company_users
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function countCompanyUsers(int $companyId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM company_users
            WHERE company_id = :company_id
        ');
        $stmt->execute(['company_id' => $companyId]);

        return (int) $stmt->fetchColumn();
    }
}
