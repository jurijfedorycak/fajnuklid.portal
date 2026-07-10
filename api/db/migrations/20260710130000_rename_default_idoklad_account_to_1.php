<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Follow-up to 20260710120000_merge_default_idoklad_account_into_1.php: with
 * the resync duplicates already removed, every remaining 'default'-keyed
 * invoice is safe to rename to its permanent key '1' — account '1' is the
 * same underlying iDoklad account 'default' always was, and the prior
 * migration already eliminated any (company_id, idoklad_id) collision risk
 * between the two keys.
 *
 * This migration only logs the leftover count — no rename yet. Once that
 * count has been reviewed, the actual rename ships as a further follow-up.
 */
final class RenameDefaultIdokladAccountTo1 extends AbstractMigration
{
    public function up(): void
    {
        $count = (int) $this->query("
            SELECT COUNT(*) AS cnt FROM invoices WHERE idoklad_account = 'default'
        ")->fetchColumn();

        $this->output->writeln(sprintf(
            '     <info>%d invoice(s) under idoklad_account = \'default\'</info>',
            $count
        ));
    }

    public function down(): void
    {
    }
}
