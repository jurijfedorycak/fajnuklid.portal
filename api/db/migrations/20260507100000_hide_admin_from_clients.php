<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a hidden_from_clients flag to staff_contacts so internal/system accounts
 * (e.g. admin@fajnuklid.cz) can have admin permissions without appearing in the
 * public Kontakt page team list. The bootstrap admin row is flagged hidden.
 */
final class HideAdminFromClients extends AbstractMigration
{
    public function up(): void
    {
        $this->table('staff_contacts')
            ->addColumn('hidden_from_clients', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'photo_url',
            ])
            ->update();

        $this->execute("
            UPDATE staff_contacts
            SET hidden_from_clients = 1
            WHERE email = 'admin@fajnuklid.cz'
        ");
    }

    public function down(): void
    {
        $this->table('staff_contacts')
            ->removeColumn('hidden_from_clients')
            ->update();
    }
}
