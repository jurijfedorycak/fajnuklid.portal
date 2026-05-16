<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * Persistence for the per-IČO time rounding rule set.
 *
 * Each company owns 0..N rules; together they form a piecewise function
 * applied to FreshQR cleaning durations in TimeRoundingService. Saves are
 * "replace all" (mirrors LocationRepository) — the admin edits the rule list
 * as a single editable section and the entire set is rewritten on submit.
 */
class CompanyRoundingRuleRepository
{
    private const ALLOWED_DIRECTIONS = ['up', 'down', 'nearest', 'none'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Return the rule set for one company, sorted in evaluation order.
     *
     * @return array<int,array{id:int,company_id:int,threshold_minutes:int,interval_minutes:int,direction:string}>
     */
    public function findByCompanyId(int $companyId): array
    {
        $stmt = $this->db->prepare('
            SELECT
                id,
                company_id,
                threshold_minutes,
                interval_minutes,
                direction
            FROM company_rounding_rules
            WHERE company_id = :company_id
            ORDER BY threshold_minutes ASC, id ASC
        ');
        $stmt->execute(['company_id' => $companyId]);

        return array_map([self::class, 'castRow'], $stmt->fetchAll());
    }

    /**
     * Bulk fetch keyed by company_id. Used by FreshQRService so a single
     * portal page render touches the table once regardless of how many IČOs
     * the user has access to.
     *
     * @param array<int,int> $companyIds
     * @return array<int, array<int,array{id:int,company_id:int,threshold_minutes:int,interval_minutes:int,direction:string}>>
     */
    public function findByCompanyIds(array $companyIds): array
    {
        $unique = array_values(array_unique(array_filter(array_map('intval', $companyIds), fn($id) => $id > 0)));
        if ($unique === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $stmt = $this->db->prepare("
            SELECT
                id,
                company_id,
                threshold_minutes,
                interval_minutes,
                direction
            FROM company_rounding_rules
            WHERE company_id IN ({$placeholders})
            ORDER BY company_id ASC, threshold_minutes ASC, id ASC
        ");
        $stmt->execute($unique);

        $byCompany = [];
        foreach ($stmt->fetchAll() as $row) {
            $cast = self::castRow($row);
            $byCompany[$cast['company_id']][] = $cast;
        }
        return $byCompany;
    }

    /**
     * Replace the entire rule set for one company. The admin form holds the
     * rule list as an editable section — re-creating the rows is simpler than
     * diffing and avoids drift between the FE order and DB ids.
     *
     * Wrapped in a transaction only when one isn't already active, so
     * AdminController's outer transaction stays the source of truth.
     *
     * @param array<int,array{threshold_minutes:int,interval_minutes:int,direction:string}> $rules
     */
    public function replaceForCompany(int $companyId, array $rules): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare('DELETE FROM company_rounding_rules WHERE company_id = :company_id');
            $delete->execute(['company_id' => $companyId]);

            // Drop non-array entries up front so the sort + bind loop below can trust the
            // shape — caller validation already rejects these, but the repo stays safe
            // even if a future caller skips that step.
            $cleanRules = array_values(array_filter($rules, 'is_array'));

            if ($cleanRules !== []) {
                $insert = $this->db->prepare('
                    INSERT INTO company_rounding_rules (
                        company_id,
                        threshold_minutes,
                        interval_minutes,
                        direction,
                        created_at,
                        updated_at
                    ) VALUES (
                        :company_id,
                        :threshold_minutes,
                        :interval_minutes,
                        :direction,
                        NOW(),
                        NOW()
                    )
                ');

                $sorted = $cleanRules;
                usort($sorted, static function (array $a, array $b): int {
                    return ((int) ($a['threshold_minutes'] ?? 0)) <=> ((int) ($b['threshold_minutes'] ?? 0));
                });

                foreach ($sorted as $rule) {
                    $direction = is_string($rule['direction'] ?? null)
                        ? strtolower(trim($rule['direction']))
                        : 'none';
                    if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
                        $direction = 'none';
                    }
                    $interval = max(0, (int) ($rule['interval_minutes'] ?? 0));
                    // Treat interval=0 and direction=none as logically equivalent — both
                    // mean "this range is exempt" — and normalise to keep the row consistent.
                    if ($interval === 0) {
                        $direction = 'none';
                    } elseif ($direction === 'none') {
                        $interval = 0;
                    }

                    $insert->execute([
                        'company_id' => $companyId,
                        'threshold_minutes' => max(0, (int) ($rule['threshold_minutes'] ?? 0)),
                        'interval_minutes' => $interval,
                        'direction' => $direction,
                    ]);
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteByCompanyId(int $companyId): int
    {
        $stmt = $this->db->prepare('DELETE FROM company_rounding_rules WHERE company_id = :company_id');
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,company_id:int,threshold_minutes:int,interval_minutes:int,direction:string}
     */
    private static function castRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'threshold_minutes' => (int) $row['threshold_minutes'],
            'interval_minutes' => (int) $row['interval_minutes'],
            'direction' => (string) $row['direction'],
        ];
    }
}
