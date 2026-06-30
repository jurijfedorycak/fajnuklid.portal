<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multiple named, categorised documents per company (IČO/protistrana) — replaces the
 * single companies.contract_pdf_path slot. Existing contracts are copied into the new
 * table as a "Hlavní smlouva" document so nothing is lost; the legacy column is left in
 * place (read-only) to keep the change non-destructive.
 */
final class CreateCompanyDocuments extends AbstractMigration
{
    public function up(): void
    {
        $documents = $this->table('company_documents', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $documents
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            // Free-text category label (e.g. "Dodatek", "Zimní údržba"); NULL = uncategorised.
            ->addColumn('document_type', 'string', [
                'limit' => 100,
                'null' => true,
            ])
            ->addColumn('title', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('file_path', 'string', [
                'limit' => 500,
                'null' => false,
            ])
            ->addColumn('original_filename', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('mime_type', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            // 0 for documents migrated from the legacy column whose size was never recorded.
            ->addColumn('size_bytes', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 0,
            ])
            ->addColumn('uploaded_by_user_id', 'integer', [
                'signed' => false,
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
            ->addIndex(['company_id'], [
                'name' => 'idx_company_id',
            ])
            ->addIndex(['company_id', 'document_type'], [
                'name' => 'idx_company_type',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_documents_company',
            ])
            ->addForeignKey('uploaded_by_user_id', 'login_accounts', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_documents_user',
            ])
            ->create();

        // Backfill: turn each existing contract into a "Hlavní smlouva" document.
        // SUBSTRING_INDEX on '/' yields the basename of the stored R2 key for the filename.
        $this->execute(
            "INSERT INTO company_documents
                (company_id, document_type, title, file_path, original_filename, mime_type, size_bytes, created_at, updated_at)
             SELECT
                id,
                'Hlavní smlouva',
                'Hlavní smlouva',
                contract_pdf_path,
                SUBSTRING_INDEX(contract_pdf_path, '/', -1),
                'application/pdf',
                0,
                NOW(),
                NOW()
             FROM companies
             WHERE contract_pdf_path IS NOT NULL AND contract_pdf_path <> ''"
        );
    }

    public function down(): void
    {
        $this->table('company_documents')->drop()->save();
    }
}
