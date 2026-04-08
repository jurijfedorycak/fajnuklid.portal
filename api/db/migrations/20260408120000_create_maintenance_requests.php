<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMaintenanceRequests extends AbstractMigration
{
    public function change(): void
    {
        $requests = $this->table('maintenance_requests', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $requests
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('client_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('created_by_user_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('category', 'enum', [
                'values' => ['elektro', 'voda', 'klima', 'uklid', 'pristupy', 'jine'],
                'null' => false,
            ])
            ->addColumn('location_type', 'enum', [
                'values' => ['office', 'common', 'custom'],
                'null' => false,
            ])
            ->addColumn('location_value', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('description', 'text', [
                'null' => true,
            ])
            ->addColumn('status', 'enum', [
                'values' => ['prijato', 'resi_se', 'ceka_na_potvrzeni', 'vyreseno', 'zablokovano'],
                'default' => 'prijato',
                'null' => false,
            ])
            ->addColumn('due_date', 'date', [
                'null' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addColumn('deleted_at', 'datetime', [
                'null' => true,
            ])
            ->addIndex(['client_id', 'status'], [
                'name' => 'idx_client_status',
            ])
            ->addIndex(['company_id'], [
                'name' => 'idx_company_id',
            ])
            ->addIndex(['created_at'], [
                'name' => 'idx_created_at',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_maintenance_requests_client',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_maintenance_requests_company',
            ])
            ->addForeignKey('created_by_user_id', 'login_accounts', 'id', [
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_maintenance_requests_user',
            ])
            ->create();

        $activity = $this->table('maintenance_request_activity', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $activity
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('request_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('author_type', 'enum', [
                'values' => ['client', 'admin', 'system'],
                'null' => false,
            ])
            ->addColumn('author_name', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('message', 'text', [
                'null' => true,
            ])
            ->addColumn('status_change', 'string', [
                'limit' => 40,
                'null' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['request_id'], [
                'name' => 'idx_request_id',
            ])
            ->addForeignKey('request_id', 'maintenance_requests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_request_activity_request',
            ])
            ->addForeignKey('user_id', 'login_accounts', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_request_activity_user',
            ])
            ->create();
    }
}
