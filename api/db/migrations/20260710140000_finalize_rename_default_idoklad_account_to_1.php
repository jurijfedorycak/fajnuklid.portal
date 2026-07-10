<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Finalizes the account-key merge started in
 * 20260710120000_merge_default_idoklad_account_into_1.php and confirmed by
 * 20260710130000_rename_default_idoklad_account_to_1.php (which logged the
 * 18 leftover 'default' rows, matching 1:1 the 18 duplicates already removed
 * — proof every 'default' invoice was accounted for). Renames those rows to
 * their permanent key '1'.
 */
final class FinalizeRenameDefaultIdokladAccountTo1 extends AbstractMigration
{
    public function up(): void
    {
        $before = (int) $this->query("
            SELECT COUNT(*) AS cnt FROM invoices WHERE idoklad_account = 'default'
        ")->fetchColumn();

        $this->output->writeln(sprintf(
            '     <info>%d invoice(s) under idoklad_account = \'default\' before rename</info>',
            $before
        ));

        $this->execute("
            UPDATE invoices SET idoklad_account = '1' WHERE idoklad_account = 'default'
        ");

        $after = (int) $this->query("
            SELECT COUNT(*) AS cnt FROM invoices WHERE idoklad_account = 'default'
        ")->fetchColumn();

        $this->output->writeln(sprintf(
            '     <info>%d invoice(s) remain under idoklad_account = \'default\' after rename (should be 0)</info>',
            $after
        ));
    }

    public function down(): void
    {
        // Not reversible: rows renamed to '1' can't be distinguished from
        // invoices that always belonged to account '1'.
    }
}
