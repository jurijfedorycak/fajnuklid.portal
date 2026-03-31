<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Initial database schema migration for Fajnuklid Portal.
 *
 * Creates all 11 tables with proper indexes and foreign key constraints.
 * Tables are created in dependency order to respect foreign key relationships.
 */
final class InitialSchema extends AbstractMigration
{
    public function change(): void
    {
        // ===========================
        // Independent tables (no FK dependencies)
        // ===========================

        // Table: clients
        $clients = $this->table('clients', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $clients
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('client_id', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'External client identifier',
            ])
            ->addColumn('display_name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('active', 'boolean', [
                'default' => true,
                'null' => false,
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
            ->addIndex(['client_id'], [
                'unique' => true,
                'name' => 'uk_client_id',
            ])
            ->addIndex(['active'], [
                'name' => 'idx_active',
            ])
            ->create();

        // Table: employees
        $employees = $this->table('employees', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $employees
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('first_name', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('last_name', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('phone', 'string', [
                'limit' => 20,
                'null' => true,
            ])
            ->addColumn('position', 'string', [
                'limit' => 100,
                'null' => true,
            ])
            ->addColumn('photo_url', 'string', [
                'limit' => 500,
                'null' => true,
            ])
            ->addColumn('show_name', 'boolean', [
                'default' => true,
                'null' => false,
                'comment' => 'GDPR: allow showing name to clients',
            ])
            ->addColumn('show_photo', 'boolean', [
                'default' => true,
                'null' => false,
                'comment' => 'GDPR: allow showing photo to clients',
            ])
            ->addColumn('show_phone', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'GDPR: allow showing phone to clients',
            ])
            ->addColumn('show_email', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'GDPR: allow showing email to clients',
            ])
            ->addColumn('active', 'boolean', [
                'default' => true,
                'null' => false,
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
            ->addIndex(['active'], [
                'name' => 'idx_active',
            ])
            ->addIndex(['last_name', 'first_name'], [
                'name' => 'idx_name',
            ])
            ->create();

        // Table: company_info (Fajnuklid company details)
        $companyInfo = $this->table('company_info', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $companyInfo
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('ico', 'string', [
                'limit' => 8,
                'null' => false,
            ])
            ->addColumn('dic', 'string', [
                'limit' => 12,
                'null' => true,
            ])
            ->addColumn('address', 'string', [
                'limit' => 500,
                'null' => false,
            ])
            ->addColumn('phone', 'string', [
                'limit' => 20,
                'null' => true,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('website', 'string', [
                'limit' => 255,
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
            ->create();

        // Table: fajnuklid_contacts (Support/sales contacts)
        $fajnuklidContacts = $this->table('fajnuklid_contacts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $fajnuklidContacts
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('position', 'string', [
                'limit' => 100,
                'null' => true,
            ])
            ->addColumn('phone', 'string', [
                'limit' => 20,
                'null' => true,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('photo_url', 'string', [
                'limit' => 500,
                'null' => true,
            ])
            ->addColumn('sort_order', 'integer', [
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('active', 'boolean', [
                'default' => true,
                'null' => false,
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
            ->addIndex(['active', 'sort_order'], [
                'name' => 'idx_active_sort',
            ])
            ->create();

        // ===========================
        // Tables with FK to clients
        // ===========================

        // Table: login_accounts
        $loginAccounts = $this->table('login_accounts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $loginAccounts
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('password_hash', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('client_id', 'integer', [
                'signed' => false,
                'null' => true,
            ])
            ->addColumn('portal_enabled', 'boolean', [
                'default' => true,
                'null' => false,
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
            ->addIndex(['email'], [
                'unique' => true,
                'name' => 'uk_email',
            ])
            ->addIndex(['client_id'], [
                'name' => 'idx_client_id',
            ])
            ->addIndex(['portal_enabled'], [
                'name' => 'idx_portal_enabled',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_login_accounts_client',
            ])
            ->create();

        // Table: icos
        $icos = $this->table('icos', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $icos
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('client_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('ico', 'string', [
                'limit' => 8,
                'null' => false,
                'comment' => 'Czech company identification number',
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
                'comment' => 'Company name',
            ])
            ->addColumn('address', 'string', [
                'limit' => 500,
                'null' => true,
            ])
            ->addColumn('contract_start_date', 'date', [
                'null' => true,
            ])
            ->addColumn('contract_end_date', 'date', [
                'null' => true,
            ])
            ->addColumn('contract_pdf_path', 'string', [
                'limit' => 500,
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
            ->addIndex(['ico'], [
                'unique' => true,
                'name' => 'uk_ico',
            ])
            ->addIndex(['client_id'], [
                'name' => 'idx_client_id',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_icos_client',
            ])
            ->create();

        // Table: contact_persons
        $contactPersons = $this->table('contact_persons', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $contactPersons
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('client_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('position', 'string', [
                'limit' => 100,
                'null' => true,
            ])
            ->addColumn('phone', 'string', [
                'limit' => 20,
                'null' => true,
            ])
            ->addColumn('email', 'string', [
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('is_primary', 'boolean', [
                'default' => false,
                'null' => false,
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
            ->addIndex(['client_id'], [
                'name' => 'idx_client_id',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_contact_persons_client',
            ])
            ->create();

        // ===========================
        // Tables with FK to icos
        // ===========================

        // Table: objects
        $objects = $this->table('objects', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $objects
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('ico_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('address', 'string', [
                'limit' => 500,
                'null' => true,
            ])
            ->addColumn('latitude', 'decimal', [
                'precision' => 10,
                'scale' => 8,
                'null' => true,
            ])
            ->addColumn('longitude', 'decimal', [
                'precision' => 11,
                'scale' => 8,
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
            ->addIndex(['ico_id'], [
                'name' => 'idx_ico_id',
            ])
            ->addForeignKey('ico_id', 'icos', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_objects_ico',
            ])
            ->create();

        // ===========================
        // Tables with FK to login_accounts
        // ===========================

        // Table: password_reset_tokens
        $passwordResetTokens = $this->table('password_reset_tokens', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $passwordResetTokens
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('token', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'SHA-256 hash of the token',
            ])
            ->addColumn('expires_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('used_at', 'datetime', [
                'null' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['token'], [
                'unique' => true,
                'name' => 'uk_token',
            ])
            ->addIndex(['user_id'], [
                'name' => 'idx_user_id',
            ])
            ->addIndex(['expires_at'], [
                'name' => 'idx_expires_at',
            ])
            ->addForeignKey('user_id', 'login_accounts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_password_reset_user',
            ])
            ->create();

        // Table: user_settings
        $userSettings = $this->table('user_settings', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $userSettings
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('notification_email', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('notification_invoice', 'boolean', [
                'default' => true,
                'null' => false,
            ])
            ->addColumn('notification_attendance', 'boolean', [
                'default' => true,
                'null' => false,
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
            ->addIndex(['user_id'], [
                'unique' => true,
                'name' => 'uk_user_id',
            ])
            ->addForeignKey('user_id', 'login_accounts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_user_settings_user',
            ])
            ->create();

        // ===========================
        // Junction tables
        // ===========================

        // Table: employee_object_assignments
        $employeeObjectAssignments = $this->table('employee_object_assignments', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $employeeObjectAssignments
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('employee_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('object_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['employee_id', 'object_id'], [
                'unique' => true,
                'name' => 'uk_employee_object',
            ])
            ->addIndex(['object_id'], [
                'name' => 'idx_object_id',
            ])
            ->addForeignKey('employee_id', 'employees', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_assignments_employee',
            ])
            ->addForeignKey('object_id', 'objects', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_assignments_object',
            ])
            ->create();
    }
}
