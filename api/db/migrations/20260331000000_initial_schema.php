<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Initial database schema migration for Fajnuklid Portal.
 *
 * Creates all 12 tables with proper indexes and foreign key constraints.
 * Tables are created in dependency order to respect foreign key relationships.
 *
 * Junction tables for M:N relationships:
 * - employee_locations: employees <-> locations
 * - company_contacts: companies <-> client_contacts
 * - company_users: companies <-> login_accounts
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
                'comment' => 'Soft delete timestamp',
            ])
            ->addIndex(['client_id'], [
                'unique' => true,
                'name' => 'uk_client_id',
            ])
            ->addIndex(['deleted_at'], [
                'name' => 'idx_deleted_at',
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
                'comment' => 'Soft delete timestamp',
            ])
            ->addIndex(['deleted_at'], [
                'name' => 'idx_deleted_at',
            ])
            ->addIndex(['last_name', 'first_name'], [
                'name' => 'idx_name',
            ])
            ->create();

        // Table: staff_contacts (Fajnuklid support/sales contacts)
        $staffContacts = $this->table('staff_contacts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $staffContacts
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
                'comment' => 'Soft delete timestamp',
            ])
            ->addIndex(['deleted_at', 'sort_order'], [
                'name' => 'idx_deleted_sort',
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
            ->addIndex(['portal_enabled'], [
                'name' => 'idx_portal_enabled',
            ])
            ->create();

        // Table: companies (client's registered companies with IČO)
        $companies = $this->table('companies', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $companies
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('client_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('registration_number', 'string', [
                'limit' => 8,
                'null' => false,
                'comment' => 'Czech IČO - company identification number',
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
            ->addIndex(['registration_number'], [
                'unique' => true,
                'name' => 'uk_registration_number',
            ])
            ->addIndex(['client_id'], [
                'name' => 'idx_client_id',
            ])
            ->addForeignKey('client_id', 'clients', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_companies_client',
            ])
            ->create();

        // Table: client_contacts (contact persons, linked to companies via junction table)
        $clientContacts = $this->table('client_contacts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $clientContacts
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

        // ===========================
        // Tables with FK to companies
        // ===========================

        // Table: locations (cleaning sites/objects)
        $locations = $this->table('locations', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $locations
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('company_id', 'integer', [
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
            ->addIndex(['company_id'], [
                'name' => 'idx_company_id',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_locations_company',
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

        // Table: employee_locations (assigns employees to locations)
        $employeeLocations = $this->table('employee_locations', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $employeeLocations
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('employee_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('location_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['employee_id', 'location_id'], [
                'unique' => true,
                'name' => 'uk_employee_location',
            ])
            ->addIndex(['location_id'], [
                'name' => 'idx_location_id',
            ])
            ->addForeignKey('employee_id', 'employees', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_employee_locations_employee',
            ])
            ->addForeignKey('location_id', 'locations', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_employee_locations_location',
            ])
            ->create();

        // Table: company_contacts (M:N junction between companies and client_contacts)
        $companyContacts = $this->table('company_contacts', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $companyContacts
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('contact_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('is_primary', 'boolean', [
                'default' => false,
                'null' => false,
                'comment' => 'Primary contact for this company',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['company_id', 'contact_id'], [
                'unique' => true,
                'name' => 'uk_company_contact',
            ])
            ->addIndex(['contact_id'], [
                'name' => 'idx_contact_id',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_contacts_company',
            ])
            ->addForeignKey('contact_id', 'client_contacts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_contacts_contact',
            ])
            ->create();

        // Table: company_users (M:N junction between companies and login_accounts)
        $companyUsers = $this->table('company_users', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $companyUsers
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['company_id', 'user_id'], [
                'unique' => true,
                'name' => 'uk_company_user',
            ])
            ->addIndex(['user_id'], [
                'name' => 'idx_user_id',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_users_company',
            ])
            ->addForeignKey('user_id', 'login_accounts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_company_users_user',
            ])
            ->create();
    }
}
