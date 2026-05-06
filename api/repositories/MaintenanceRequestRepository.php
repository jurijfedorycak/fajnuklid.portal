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

    private const SELECT_FIELDS = '
        r.id,
        r.client_id,
        r.company_id,
        r.created_by_user_id,
        r.title,
        r.category,
        r.description,
        r.status,
        r.due_date,
        r.created_at,
        r.updated_at,
        la.email AS created_by_email,
        co.name AS company_name,
        co.registration_number AS company_ico
    ';

    /**
     * @param int[]|null $statuses
     */
    public function findByClientId(int $clientId, ?array $statuses = null, ?int $limit = null, ?string $date = null): array
    {
        $sql = '
            SELECT ' . self::SELECT_FIELDS . '
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            LEFT JOIN companies co ON r.company_id = co.id
            WHERE r.client_id = :client_id AND r.deleted_at IS NULL
        ';

        $params = ['client_id' => $clientId];

        if (!empty($statuses)) {
            $placeholders = [];
            foreach ($statuses as $i => $s) {
                $key = 'status_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $s;
            }
            $sql .= ' AND r.status IN (' . implode(',', $placeholders) . ')';
        }

        if ($date !== null && $date !== '') {
            $sql .= ' AND DATE(r.created_at) = :date';
            $params['date'] = $date;
        }

        $sql .= ' ORDER BY r.created_at DESC';

        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findByIdForClient(int $id, int $clientId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT ' . self::SELECT_FIELDS . '
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            LEFT JOIN companies co ON r.company_id = co.id
            WHERE r.id = :id AND r.client_id = :client_id AND r.deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id, 'client_id' => $clientId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT ' . self::SELECT_FIELDS . ',
                cl.display_name AS client_display_name
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            LEFT JOIN companies co ON r.company_id = co.id
            LEFT JOIN clients cl ON r.client_id = cl.id
            WHERE r.id = :id AND r.deleted_at IS NULL
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findAllForAdmin(?int $clientId = null, ?string $status = null): array
    {
        $sql = '
            SELECT ' . self::SELECT_FIELDS . ',
                cl.display_name AS client_display_name
            FROM maintenance_requests r
            LEFT JOIN login_accounts la ON r.created_by_user_id = la.id
            LEFT JOIN companies co ON r.company_id = co.id
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

    /**
     * Returns rows: [{ date: 'YYYY-MM-DD', status: string, count: int }]
     * Excludes the `zablokovano` status — blocked requests are not surfaced on the client calendar.
     */
    public function countByDayForClient(int $clientId, int $year, int $month): array
    {
        $stmt = $this->db->prepare('
            SELECT DATE(created_at) AS d, status AS s, COUNT(*) AS c
            FROM maintenance_requests
            WHERE client_id = :client_id
              AND deleted_at IS NULL
              AND status <> :excluded_status
              AND YEAR(created_at) = :year
              AND MONTH(created_at) = :month
            GROUP BY DATE(created_at), status
            ORDER BY d ASC
        ');
        $stmt->execute([
            'client_id' => $clientId,
            'excluded_status' => 'zablokovano',
            'year' => $year,
            'month' => $month,
        ]);
        $rows = $stmt->fetchAll();
        return array_map(function ($r) {
            return ['date' => $r['d'], 'status' => $r['s'], 'count' => (int) $r['c']];
        }, $rows);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO maintenance_requests (
                client_id, company_id, created_by_user_id, title, category,
                location_type, location_value, description, status, due_date,
                created_at, updated_at
            ) VALUES (
                :client_id, :company_id, :created_by_user_id, :title, :category,
                :location_type, :location_value, :description, :status, :due_date,
                NOW(), NOW()
            )
        ');

        $stmt->execute([
            'client_id' => $data['client_id'],
            'company_id' => $data['company_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'],
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'location_type' => $data['location_type'] ?? null,
            'location_value' => $data['location_value'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'prijato',
            'due_date' => $data['due_date'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['title', 'category', 'description', 'status', 'due_date', 'company_id'];
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

    public function findActivity(int $requestId, bool $includeInternal = true): array
    {
        $sql = '
            SELECT id, request_id, user_id, author_type, author_name,
                   message, status_change, is_internal, created_at
            FROM maintenance_request_activity
            WHERE request_id = :request_id
        ';

        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }

        $sql .= ' ORDER BY created_at ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['request_id' => $requestId]);

        return $stmt->fetchAll();
    }

    public function addActivity(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO maintenance_request_activity (
                request_id, user_id, author_type, author_name,
                message, status_change, is_internal, created_at
            ) VALUES (
                :request_id, :user_id, :author_type, :author_name,
                :message, :status_change, :is_internal, NOW()
            )
        ');

        $stmt->execute([
            'request_id' => $data['request_id'],
            'user_id' => $data['user_id'] ?? null,
            'author_type' => $data['author_type'],
            'author_name' => $data['author_name'] ?? null,
            'message' => $data['message'] ?? null,
            'status_change' => $data['status_change'] ?? null,
            'is_internal' => !empty($data['is_internal']) ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    // ─────────── Attachments ───────────

    public function findAttachments(int $requestId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, request_id, phase, file_path, original_filename,
                   mime_type, size_bytes, created_at
            FROM maintenance_request_attachments
            WHERE request_id = :request_id
            ORDER BY created_at ASC, id ASC
        ');
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll();
    }

    public function countAttachments(int $requestId, string $phase): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM maintenance_request_attachments
            WHERE request_id = :request_id AND phase = :phase
        ');
        $stmt->execute(['request_id' => $requestId, 'phase' => $phase]);
        return (int) $stmt->fetchColumn();
    }

    public function addAttachment(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO maintenance_request_attachments (
                request_id, phase, file_path, original_filename,
                mime_type, size_bytes, uploaded_by_user_id, created_at
            ) VALUES (
                :request_id, :phase, :file_path, :original_filename,
                :mime_type, :size_bytes, :uploaded_by_user_id, NOW()
            )
        ');
        $stmt->execute([
            'request_id' => $data['request_id'],
            'phase' => $data['phase'],
            'file_path' => $data['file_path'],
            'original_filename' => $data['original_filename'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => $data['size_bytes'],
            'uploaded_by_user_id' => $data['uploaded_by_user_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
