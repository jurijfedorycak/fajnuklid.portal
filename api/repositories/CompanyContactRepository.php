<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class CompanyContactRepository
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
                cc.id,
                cc.company_id,
                cc.contact_id,
                cc.is_primary,
                c.name AS company_name,
                ct.name AS contact_name,
                ct.position AS contact_position,
                ct.phone AS contact_phone,
                ct.email AS contact_email
            FROM company_contacts cc
            INNER JOIN companies c ON cc.company_id = c.id
            INNER JOIN client_contacts ct ON cc.contact_id = ct.id
            WHERE cc.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                cc.id,
                cc.company_id,
                cc.contact_id,
                cc.is_primary,
                ct.name AS contact_name,
                ct.position AS contact_position,
                ct.phone AS contact_phone,
                ct.email AS contact_email
            FROM company_contacts cc
            INNER JOIN client_contacts ct ON cc.contact_id = ct.id
            WHERE cc.company_id = :company_id
            ORDER BY cc.is_primary DESC, ct.name ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    public function findByContactId(int $contactId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                cc.id,
                cc.company_id,
                cc.contact_id,
                cc.is_primary,
                c.name AS company_name,
                c.registration_number
            FROM company_contacts cc
            INNER JOIN companies c ON cc.company_id = c.id
            WHERE cc.contact_id = :contact_id
            ORDER BY c.name ASC
        ');
        $stmt->execute(['contact_id' => $contactId]);

        return $stmt->fetchAll();
    }

    public function findPrimaryContact(int $companyId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                cc.id,
                cc.company_id,
                cc.contact_id,
                cc.is_primary,
                ct.name AS contact_name,
                ct.position AS contact_position,
                ct.phone AS contact_phone,
                ct.email AS contact_email
            FROM company_contacts cc
            INNER JOIN client_contacts ct ON cc.contact_id = ct.id
            WHERE cc.company_id = :company_id AND cc.is_primary = 1
            LIMIT 1
        ');
        $stmt->execute(['company_id' => $companyId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function exists(int $companyId, int $contactId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM company_contacts
            WHERE company_id = :company_id AND contact_id = :contact_id
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'contact_id' => $contactId
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $companyId, int $contactId, bool $isPrimary = false): int
    {
        $this->db->beginTransaction();

        try {
            if ($isPrimary) {
                $this->clearPrimary($companyId);
            }

            $stmt = $this->db->prepare('
                INSERT INTO company_contacts (company_id, contact_id, is_primary)
                VALUES (:company_id, :contact_id, :is_primary)
            ');

            $stmt->execute([
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'is_primary' => $isPrimary ? 1 : 0
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $id;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        $this->db->beginTransaction();

        try {
            if (isset($data['is_primary']) && $data['is_primary']) {
                $record = $this->findById($id);
                if ($record) {
                    $this->clearPrimary($record['company_id']);
                }
            }

            $stmt = $this->db->prepare('
                UPDATE company_contacts
                SET is_primary = :is_primary
                WHERE id = :id
            ');
            $stmt->execute([
                'id' => $id,
                'is_primary' => ($data['is_primary'] ?? false) ? 1 : 0
            ]);

            $result = $stmt->rowCount() > 0;
            $this->db->commit();

            return $result;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function setPrimary(int $companyId, int $contactId): bool
    {
        $this->db->beginTransaction();

        try {
            $this->clearPrimary($companyId);

            $stmt = $this->db->prepare('
                UPDATE company_contacts
                SET is_primary = 1
                WHERE company_id = :company_id AND contact_id = :contact_id
            ');
            $stmt->execute([
                'company_id' => $companyId,
                'contact_id' => $contactId
            ]);

            $this->db->commit();
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function clearPrimary(int $companyId): int
    {
        $stmt = $this->db->prepare('
            UPDATE company_contacts
            SET is_primary = 0
            WHERE company_id = :company_id AND is_primary = 1
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->rowCount();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM company_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByCompanyAndContact(int $companyId, int $contactId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM company_contacts
            WHERE company_id = :company_id AND contact_id = :contact_id
        ');
        $stmt->execute([
            'company_id' => $companyId,
            'contact_id' => $contactId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteByCompanyId(int $companyId): int
    {
        $stmt = $this->db->prepare('DELETE FROM company_contacts WHERE company_id = :company_id');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->rowCount();
    }

    public function deleteByContactId(int $contactId): int
    {
        $stmt = $this->db->prepare('DELETE FROM company_contacts WHERE contact_id = :contact_id');
        $stmt->execute(['contact_id' => $contactId]);

        return $stmt->rowCount();
    }

    public function syncCompanyContacts(int $companyId, array $contactIds, ?int $primaryContactId = null): void
    {
        $this->db->beginTransaction();

        try {
            $this->deleteByCompanyId($companyId);

            $stmt = $this->db->prepare('
                INSERT INTO company_contacts (company_id, contact_id, is_primary)
                VALUES (:company_id, :contact_id, :is_primary)
            ');

            foreach ($contactIds as $contactId) {
                if (!is_int($contactId) || $contactId <= 0) {
                    throw new \InvalidArgumentException('Invalid contact ID provided');
                }
                $stmt->execute([
                    'company_id' => $companyId,
                    'contact_id' => $contactId,
                    'is_primary' => ($primaryContactId !== null && $primaryContactId === $contactId) ? 1 : 0
                ]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getContactIdsByCompany(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT contact_id
            FROM company_contacts
            WHERE company_id = :company_id
        ');
        $stmt->execute(['company_id' => $companyId]);

        return array_column($stmt->fetchAll(), 'contact_id');
    }

    public function getCompanyIdsByContact(int $contactId): array
    {
        $stmt = $this->db->prepare('
            SELECT company_id
            FROM company_contacts
            WHERE contact_id = :contact_id
        ');
        $stmt->execute(['contact_id' => $contactId]);

        return array_column($stmt->fetchAll(), 'company_id');
    }
}
