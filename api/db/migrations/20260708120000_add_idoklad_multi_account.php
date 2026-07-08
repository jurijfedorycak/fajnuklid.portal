<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Support reading invoices from multiple iDoklad accounts (legal entities the
 * company splits invoicing across for tax optimization).
 *
 * - idoklad_tokens gains account_key so each account caches its own OAuth token.
 * - invoices gains idoklad_account (the issuing account) and its uniqueness moves
 *   from idoklad_id alone to (idoklad_account, idoklad_id), because the same
 *   iDoklad invoice id can legitimately appear in two different accounts.
 *
 * Existing rows are backfilled to the 'default' key, matching the legacy
 * single-account credentials exposed as account 'default'.
 */
final class AddIdokladMultiAccount extends AbstractMigration
{
    public function up(): void
    {
        $this->table('idoklad_tokens')
            ->addColumn('account_key', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => 'default',
                'after' => 'id',
            ])
            ->addIndex(['account_key'], ['name' => 'idx_idoklad_tokens_account'])
            ->update();

        $this->table('invoices')
            ->addColumn('idoklad_account', 'string', [
                'limit' => 50,
                'null' => false,
                'default' => 'default',
                'after' => 'idoklad_id',
            ])
            ->removeIndexByName('uk_idoklad_id')
            ->addIndex(['idoklad_account', 'idoklad_id'], [
                'unique' => true,
                'name' => 'uk_account_idoklad_id',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('invoices')
            ->removeIndexByName('uk_account_idoklad_id')
            ->addIndex(['idoklad_id'], ['unique' => true, 'name' => 'uk_idoklad_id'])
            ->removeColumn('idoklad_account')
            ->update();

        $this->table('idoklad_tokens')
            ->removeIndexByName('idx_idoklad_tokens_account')
            ->removeColumn('account_key')
            ->update();
    }
}
