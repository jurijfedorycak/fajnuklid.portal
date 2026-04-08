<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class InvoiceRepository
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
                i.id,
                i.idoklad_id,
                i.company_id,
                i.document_number,
                i.variable_symbol,
                i.date_issued,
                i.date_due,
                i.date_paid,
                i.total_amount,
                i.currency_code,
                i.is_paid,
                i.payment_status,
                i.description,
                i.synced_at,
                i.created_at,
                i.updated_at,
                c.name AS company_name,
                c.registration_number
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            WHERE i.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByIdokladId(int $idokladId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                idoklad_id,
                company_id,
                document_number,
                variable_symbol,
                date_issued,
                date_due,
                date_paid,
                total_amount,
                currency_code,
                is_paid,
                payment_status,
                description,
                synced_at,
                created_at,
                updated_at
            FROM invoices
            WHERE idoklad_id = :idoklad_id
        ');
        $stmt->execute(['idoklad_id' => $idokladId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function findByCompanyId(int $companyId, ?string $status = null): array
    {
        $sql = '
            SELECT
                id,
                idoklad_id,
                company_id,
                document_number,
                variable_symbol,
                date_issued,
                date_due,
                date_paid,
                total_amount,
                currency_code,
                is_paid,
                payment_status,
                description,
                synced_at
            FROM invoices
            WHERE company_id = :company_id
        ';

        $params = ['company_id' => $companyId];

        if ($status !== null) {
            $sql .= ' AND payment_status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY date_issued DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findByUserId(int $userId, ?string $ico = null): array
    {
        $sql = '
            SELECT
                i.id,
                i.idoklad_id,
                i.company_id,
                i.document_number,
                i.variable_symbol,
                i.date_issued,
                i.date_due,
                i.date_paid,
                i.total_amount,
                i.currency_code,
                i.is_paid,
                i.payment_status,
                i.description,
                i.synced_at,
                c.name AS company_name,
                c.registration_number
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
        ';

        $params = ['user_id' => $userId];

        if ($ico !== null && $ico !== '') {
            $sql .= ' AND c.registration_number = :ico';
            $params['ico'] = $ico;
        }

        $sql .= ' ORDER BY i.date_issued DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getTotalsForUser(int $userId, ?string $ico = null, ?string $from = null, ?string $to = null): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN i.payment_status = \'paid\' THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN i.payment_status = \'unpaid\' THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN i.payment_status = \'overdue\' THEN 1 ELSE 0 END) AS overdue_count,
                SUM(CASE WHEN i.payment_status != \'paid\' THEN i.total_amount ELSE 0 END) AS debt_amount
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
        ';

        $params = ['user_id' => $userId];

        if ($ico !== null && $ico !== '') {
            $sql .= ' AND c.registration_number = :ico';
            $params['ico'] = $ico;
        }

        if ($from !== null && $from !== '') {
            $sql .= ' AND i.date_issued >= :date_from';
            $params['date_from'] = $from;
        }

        if ($to !== null && $to !== '') {
            $sql .= ' AND i.date_issued <= :date_to';
            $params['date_to'] = $to;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return [
            'all' => (int) ($result['total_count'] ?? 0),
            'paid' => (int) ($result['paid_count'] ?? 0),
            'unpaid' => (int) ($result['unpaid_count'] ?? 0),
            'overdue' => (int) ($result['overdue_count'] ?? 0),
            'debt' => (float) ($result['debt_amount'] ?? 0),
        ];
    }

    /**
     * Find the most recent invoices for a user, optionally filtered by ICO and date range.
     */
    public function findRecentForUser(int $userId, ?string $ico = null, ?string $from = null, ?string $to = null, int $limit = 5): array
    {
        $sql = '
            SELECT
                i.id,
                i.company_id,
                i.document_number,
                i.variable_symbol,
                i.date_issued,
                i.date_due,
                i.date_paid,
                i.total_amount,
                i.currency_code,
                i.is_paid,
                i.payment_status,
                c.name AS company_name,
                c.registration_number
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
        ';

        $params = ['user_id' => $userId];

        if ($ico !== null && $ico !== '') {
            $sql .= ' AND c.registration_number = :ico';
            $params['ico'] = $ico;
        }

        if ($from !== null && $from !== '') {
            $sql .= ' AND i.date_issued >= :date_from';
            $params['date_from'] = $from;
        }

        if ($to !== null && $to !== '') {
            $sql .= ' AND i.date_issued <= :date_to';
            $params['date_to'] = $to;
        }

        $sql .= ' ORDER BY i.date_issued DESC, i.id DESC LIMIT :row_limit';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':row_limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Find the next unpaid invoice with a due date >= today for a user.
     * Returns null if no upcoming unpaid invoices exist.
     */
    public function findNextDueForUser(int $userId, ?string $ico = null): ?array
    {
        $sql = '
            SELECT
                i.id,
                i.document_number,
                i.date_due,
                i.total_amount,
                i.currency_code,
                i.payment_status
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE cu.user_id = :user_id
              AND i.payment_status != \'paid\'
              AND i.date_due >= CURDATE()
        ';

        $params = ['user_id' => $userId];

        if ($ico !== null && $ico !== '') {
            $sql .= ' AND c.registration_number = :ico';
            $params['ico'] = $ico;
        }

        $sql .= ' ORDER BY i.date_due ASC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function upsertFromIdoklad(array $data): int
    {
        // Check if invoice exists
        $existing = $this->findByIdokladId($data['idoklad_id']);

        if ($existing !== null) {
            // Update existing invoice
            $stmt = $this->db->prepare('
                UPDATE invoices SET
                    company_id = :company_id,
                    document_number = :document_number,
                    variable_symbol = :variable_symbol,
                    date_issued = :date_issued,
                    date_due = :date_due,
                    date_paid = :date_paid,
                    total_amount = :total_amount,
                    currency_code = :currency_code,
                    is_paid = :is_paid,
                    payment_status = :payment_status,
                    description = :description,
                    synced_at = NOW()
                WHERE idoklad_id = :idoklad_id
            ');

            $stmt->execute([
                'idoklad_id' => $data['idoklad_id'],
                'company_id' => $data['company_id'],
                'document_number' => $data['document_number'],
                'variable_symbol' => $data['variable_symbol'] ?? null,
                'date_issued' => $data['date_issued'],
                'date_due' => $data['date_due'],
                'date_paid' => $data['date_paid'] ?? null,
                'total_amount' => $data['total_amount'],
                'currency_code' => $data['currency_code'] ?? 'CZK',
                'is_paid' => $data['is_paid'] ? 1 : 0,
                'payment_status' => $data['payment_status'],
                'description' => $data['description'] ?? null,
            ]);

            return (int) $existing['id'];
        }

        // Insert new invoice
        $stmt = $this->db->prepare('
            INSERT INTO invoices (
                idoklad_id,
                company_id,
                document_number,
                variable_symbol,
                date_issued,
                date_due,
                date_paid,
                total_amount,
                currency_code,
                is_paid,
                payment_status,
                description,
                synced_at,
                created_at
            ) VALUES (
                :idoklad_id,
                :company_id,
                :document_number,
                :variable_symbol,
                :date_issued,
                :date_due,
                :date_paid,
                :total_amount,
                :currency_code,
                :is_paid,
                :payment_status,
                :description,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'idoklad_id' => $data['idoklad_id'],
            'company_id' => $data['company_id'],
            'document_number' => $data['document_number'],
            'variable_symbol' => $data['variable_symbol'] ?? null,
            'date_issued' => $data['date_issued'],
            'date_due' => $data['date_due'],
            'date_paid' => $data['date_paid'] ?? null,
            'total_amount' => $data['total_amount'],
            'currency_code' => $data['currency_code'] ?? 'CZK',
            'is_paid' => $data['is_paid'] ? 1 : 0,
            'payment_status' => $data['payment_status'],
            'description' => $data['description'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function getLastSyncTime(int $companyId): ?string
    {
        $stmt = $this->db->prepare('
            SELECT MAX(synced_at) AS last_sync
            FROM invoices
            WHERE company_id = :company_id
        ');
        $stmt->execute(['company_id' => $companyId]);
        $result = $stmt->fetch();

        return $result['last_sync'] ?? null;
    }

    public function userOwnsInvoice(int $userId, int $invoiceId): bool
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) AS cnt
            FROM invoices i
            INNER JOIN companies c ON i.company_id = c.id
            INNER JOIN company_users cu ON c.id = cu.company_id
            WHERE i.id = :invoice_id AND cu.user_id = :user_id
        ');
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
        ]);
        $result = $stmt->fetch();

        return (int) $result['cnt'] > 0;
    }
}
