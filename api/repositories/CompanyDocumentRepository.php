<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class CompanyDocumentRepository
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
                company_id,
                document_type,
                title,
                file_path,
                original_filename,
                mime_type,
                size_bytes,
                uploaded_by_user_id,
                created_at,
                updated_at
            FROM company_documents
            WHERE id = :id
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
                document_type,
                title,
                file_path,
                original_filename,
                mime_type,
                size_bytes,
                uploaded_by_user_id,
                created_at,
                updated_at
            FROM company_documents
            WHERE company_id = :company_id
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Batch-load documents for several companies at once so the client portal can render
     * a multi-company view without an N+1 query. Returns a flat list; group by company_id.
     */
    public function findByCompanyIds(array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        if (empty($companyIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT
                id,
                company_id,
                document_type,
                title,
                file_path,
                original_filename,
                mime_type,
                size_bytes,
                uploaded_by_user_id,
                created_at,
                updated_at
            FROM company_documents
            WHERE company_id IN ({$placeholders})
            ORDER BY company_id ASC, created_at ASC, id ASC
        ");
        $stmt->execute($companyIds);

        return $stmt->fetchAll();
    }

    public function countByCompanyId(int $companyId): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM company_documents WHERE company_id = :company_id
        ');
        $stmt->execute(['company_id' => $companyId]);

        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO company_documents (
                company_id,
                document_type,
                title,
                file_path,
                original_filename,
                mime_type,
                size_bytes,
                uploaded_by_user_id,
                created_at,
                updated_at
            ) VALUES (
                :company_id,
                :document_type,
                :title,
                :file_path,
                :original_filename,
                :mime_type,
                :size_bytes,
                :uploaded_by_user_id,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'company_id' => $data['company_id'],
            'document_type' => $data['document_type'] ?? null,
            'title' => $data['title'],
            'file_path' => $data['file_path'],
            'original_filename' => $data['original_filename'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => (int) ($data['size_bytes'] ?? 0),
            'uploaded_by_user_id' => $data['uploaded_by_user_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update document metadata only (title / category). The stored file is immutable —
     * replacing a file means uploading a new document.
     */
    public function updateMeta(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['document_type', 'title'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE company_documents SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM company_documents WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
