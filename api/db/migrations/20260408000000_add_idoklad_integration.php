<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add iDoklad integration tables for invoice caching and OAuth token storage.
 *
 * Tables:
 * - invoices: Cache of invoices from iDoklad API
 * - idoklad_tokens: OAuth2 access token storage
 */
final class AddIdokladIntegration extends AbstractMigration
{
    public function change(): void
    {
        // OAuth2 token storage
        $this->table('idoklad_tokens', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
            ])
            ->addColumn('access_token', 'text', [
                'null' => false,
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->create();

        // Invoice cache from iDoklad
        $this->table('invoices', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false,
            ])
            ->addColumn('idoklad_id', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'iDoklad invoice ID',
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('document_number', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('variable_symbol', 'string', [
                'limit' => 20,
                'null' => true,
            ])
            ->addColumn('date_issued', 'date', [
                'null' => false,
            ])
            ->addColumn('date_due', 'date', [
                'null' => false,
            ])
            ->addColumn('date_paid', 'date', [
                'null' => true,
            ])
            ->addColumn('total_amount', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'null' => false,
            ])
            ->addColumn('currency_code', 'string', [
                'limit' => 3,
                'null' => false,
                'default' => 'CZK',
            ])
            ->addColumn('is_paid', 'boolean', [
                'null' => false,
                'default' => false,
            ])
            ->addColumn('payment_status', 'enum', [
                'values' => ['unpaid', 'paid', 'partial', 'overdue'],
                'null' => false,
                'default' => 'unpaid',
            ])
            ->addColumn('description', 'string', [
                'limit' => 500,
                'null' => true,
            ])
            ->addColumn('synced_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['idoklad_id'], [
                'unique' => true,
                'name' => 'uk_idoklad_id',
            ])
            ->addIndex(['company_id'], [
                'name' => 'idx_company_id',
            ])
            ->addIndex(['payment_status'], [
                'name' => 'idx_payment_status',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_invoices_company',
            ])
            ->create();
    }
}
