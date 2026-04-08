<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class MaintenanceRequestRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * List all requests visible to a given client (multi-tenant scope).
     */
    public function findByClientId(int $clientId, ?string $status = null): array
    {
        $sql = '
            SELECT
                r.id,
                r.client_id,
                r.company_id,
                r.created_by_user_id,
                r.title,
                r.category,
                r.location_type,
                r.location_value,
                r.description,
                r.status,
                r.due_date,
                r.created_at,
                r.updated_at,
                la.email AS created_by_email
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            WHERE r.client_id = :client_id AND r.deleted_at IS NULL
        ';

        $params = ['client_id' => $clientId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY r.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Find a single request by id, scoped to a client (returns null if not owned).
     */
    public function findByIdForClient(int $id, int $clientId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                r.id,
                r.client_id,
                r.company_id,
                r.created_by_user_id,
                r.title,
                r.category,
                r.location_type,
                r.location_value,
                r.description,
                r.status,
                r.due_date,
                r.created_at,
                r.updated_at,
                la.email AS created_by_email
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            WHERE r.id = :id AND r.client_id = :client_id AND r.deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id, 'client_id' => $clientId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Find any request by id (admin context, no tenant filter).
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                r.id,
                r.client_id,
                r.company_id,
                r.created_by_user_id,
                r.title,
                r.category,
                r.location_type,
                r.location_value,
                r.description,
                r.status,
                r.due_date,
                r.created_at,
                r.updated_at,
                la.email AS created_by_email,
                cl.display_name AS client_display_name
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            LEFT JOIN clients cl ON r.client_id = cl.id
            WHERE r.id = :id AND r.deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Admin: list all requests across clients with optional filters.
     */
    public function findAllForAdmin(?int $clientId = null, ?string $status = null): array
    {
        $sql = '
            SELECT
                r.id,
                r.client_id,
                r.company_id,
                r.created_by_user_id,
                r.title,
                r.category,
                r.location_type,
                r.location_value,
                r.status,
                r.due_date,
                r.created_at,
                r.updated_at,
                cl.display_name AS client_display_name
            FROM maintenance_requests r
            LEFT JOIN clients cl ON r.client_id = cl.id
            WHERE r.deleted_at IS NULL
        ';

        $params = [];

        if ($clientId !== null) {
            $sql .= ' AND r.client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY r.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO maintenance_requests (
                client_id,
                company_id,
                created_by_user_id,
                title,
                category,
                location_type,
                location_value,
                description,
                status,
                due_date,
                created_at,
                updated_at
            ) VALUES (
                :client_id,
                :company_id,
                :created_by_user_id,
                :title,
                :category,
                :location_type,
                :location_value,
                :description,
                :status,
                :due_date,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'company_id' => $data['company_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'],
            'title' => $data['title'],
            'category' => $data['category'],
            'location_type' => $data['location_type'],
            'location_value' => $data['location_value'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'prijato',
            'due_date' => $data['due_date'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['title', 'category', 'location_type', 'location_value', 'description', 'status', 'due_date', 'company_id'];
        $fields = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = NOW()';

        $sql = 'UPDATE maintenance_requests SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare('
            UPDATE maintenance_requests
            SET status = :status, updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id, 'status' => $status]);

        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare('
            UPDATE maintenance_requests
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Find activity timeline for a request, oldest first.
     */
    public function findActivity(int $requestId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                request_id,
                user_id,
                author_type,
                author_name,
                message,
                status_change,
                created_at
            FROM maintenance_request_activity
            WHERE request_id = :request_id
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute(['request_id' => $requestId]);

        return $stmt->fetchAll();
    }

    public function addActivity(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO maintenance_request_activity (
                request_id,
                user_id,
                author_type,
                author_name,
                message,
                status_change,
                created_at
            ) VALUES (
                :request_id,
                :user_id,
                :author_type,
                :author_name,
                :message,
                :status_change,
                NOW()
            )
        ');

        $stmt->execute([
            'request_id' => $data['request_id'],
            'user_id' => $data['user_id'] ?? null,
            'author_type' => $data['author_type'],
            'author_name' => $data['author_name'] ?? null,
            'message' => $data['message'] ?? null,
            'status_change' => $data['status_change'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }
}
