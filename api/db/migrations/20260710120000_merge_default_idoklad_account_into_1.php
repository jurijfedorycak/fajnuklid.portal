<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One-time data fix for the multi-account rollout in
 * 20260708120000_add_idoklad_multi_account.php: production's IDOKLAD_ACCOUNTS
 * was configured as "1,2" with no 'default' entry, so the account that used
 * to sync under the legacy 'default' key started syncing under '1' instead.
 * Every invoice already stored as 'default' then got re-fetched and inserted
 * a second time under '1', because uniqueness is (idoklad_account, idoklad_id).
 *
 * This migration only removes those duplicates, logging the duplicate count
 * before and after the cleanup. The rename of 'default' -> '1' is
 * deliberately left to a follow-up migration once that count has been
 * reviewed.
 */
final class MergeDefaultIdokladAccountInto1 extends AbstractMigration
{
    /**
     * Duplicates created under '1' for invoices that already exist under
     * 'default'. Account '1' is the same underlying iDoklad account
     * 'default' always was, so their idoklad_id values share one numbering
     * space — a match on (company_id, idoklad_id, document_number) across
     * the two keys is conclusively the same real invoice, not a coincidental
     * collision.
     */
    private const DUPLICATE_COUNT_SQL = "
        SELECT COUNT(*) AS cnt
        FROM invoices o
        JOIN invoices d
          ON o.company_id = d.company_id
         AND o.idoklad_id = d.idoklad_id
         AND o.document_number = d.document_number
         AND o.idoklad_account <> d.idoklad_account
        WHERE d.idoklad_account = 'default'
          AND o.idoklad_account <> 'default'
    ";

    public function up(): void
    {
        $before = (int) $this->query(self::DUPLICATE_COUNT_SQL)->fetchColumn();

        $this->output->writeln(sprintf(
            '     <info>%d duplicate invoice(s) found before cleanup</info>',
            $before
        ));

        $this->execute("
            DELETE o FROM invoices o
            JOIN invoices d
              ON o.company_id = d.company_id
             AND o.idoklad_id = d.idoklad_id
             AND o.document_number = d.document_number
             AND o.idoklad_account <> d.idoklad_account
            WHERE d.idoklad_account = 'default'
              AND o.idoklad_account <> 'default'
        ");

        $after = (int) $this->query(self::DUPLICATE_COUNT_SQL)->fetchColumn();

        $this->output->writeln(sprintf(
            '     <info>%d duplicate invoice(s) remain after cleanup (should be 0)</info>',
            $after
        ));
    }

    public function down(): void
    {
        // Not reversible: the deleted duplicate rows are gone for good.
    }
}
