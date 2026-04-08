<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class AlignRequestsWithSpec extends AbstractMigration
{
    public function up(): void
    {
        // Drop existing rows whose category/location values are incompatible with the new schema.
        // Test data only — see plan.
        $this->execute("DELETE FROM `maintenance_request_activity`");
        $this->execute("DELETE FROM `maintenance_requests`");

        // Replace category enum with spec values + make category and location nullable
        $this->execute(
            "ALTER TABLE `maintenance_requests`
                MODIFY `category` ENUM('reklamace','mimoradna_prace','jine') NULL,
                MODIFY `location_type` ENUM('office','common','custom') NULL,
                MODIFY `location_value` VARCHAR(255) NULL"
        );

        // Attachments table
        $attachments = $this->table('maintenance_request_attachments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $attachments
            ->addColumn('id', 'integer', ['signed' => false, 'identity' => true])
            ->addColumn('request_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('phase', 'enum', [
                'values' => ['before', 'after'],
                'default' => 'before',
                'null' => false,
            ])
            ->addColumn('file_path', 'string', ['limit' => 500, 'null' => false])
            ->addColumn('original_filename', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('size_bytes', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('uploaded_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['request_id'], ['name' => 'idx_request_id'])
            ->addForeignKey('request_id', 'maintenance_requests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_request_attachment_request',
            ])
            ->addForeignKey('uploaded_by_user_id', 'login_accounts', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_request_attachment_user',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('maintenance_request_attachments')->drop()->update();

        $this->execute(
            "ALTER TABLE `maintenance_requests`
                MODIFY `category` ENUM('elektro','voda','klima','uklid','pristupy','jine') NOT NULL,
                MODIFY `location_type` ENUM('office','common','custom') NOT NULL"
        );
    }
}
