<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds is_admin to login_accounts and links staff_contacts to login_accounts.
 * Every staff_contacts row with an associated login becomes an admin —
 * staff_contacts is the new source of truth for admin permissions.
 */
final class AddStaffAdminLogin extends AbstractMigration
{
    public function up(): void
    {
        $this->table('login_accounts')
            ->addColumn('is_admin', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'portal_enabled',
            ])
            ->addIndex(['is_admin'], ['name' => 'idx_is_admin'])
            ->update();

        $this->table('staff_contacts')
            ->addColumn('user_id', 'integer', [
                'signed' => false,
                'null' => true,
                'after' => 'email',
            ])
            ->addIndex(['user_id'], ['unique' => true, 'name' => 'uk_user_id'])
            ->addForeignKey('user_id', 'login_accounts', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_staff_contacts_user',
            ])
            ->update();

        $this->execute("
            INSERT INTO staff_contacts (name, position, email, user_id, sort_order, created_at, updated_at)
            SELECT 'Administrátor', 'Správce systému', la.email, la.id, 0, NOW(), NOW()
            FROM login_accounts la
            WHERE la.email = 'admin@fajnuklid.cz'
              AND NOT EXISTS (SELECT 1 FROM staff_contacts sc WHERE sc.user_id = la.id)
        ");

        $this->execute("
            UPDATE staff_contacts sc
            JOIN login_accounts la ON LOWER(la.email) = LOWER(sc.email)
            SET sc.user_id = la.id, sc.updated_at = NOW()
            WHERE sc.user_id IS NULL AND sc.email IS NOT NULL AND sc.deleted_at IS NULL
        ");

        $this->execute("
            UPDATE login_accounts la
            JOIN staff_contacts sc ON sc.user_id = la.id
            SET la.is_admin = 1
            WHERE sc.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        $this->table('staff_contacts')
            ->dropForeignKey('user_id', 'fk_staff_contacts_user')
            ->removeIndexByName('uk_user_id')
            ->removeColumn('user_id')
            ->update();

        $this->table('login_accounts')
            ->removeIndexByName('idx_is_admin')
            ->removeColumn('is_admin')
            ->update();
    }
}
