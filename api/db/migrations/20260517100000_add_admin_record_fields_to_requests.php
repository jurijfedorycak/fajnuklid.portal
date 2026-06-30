<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAdminRecordFieldsToRequests extends AbstractMigration
{
    public function change(): void
    {
        $this->table('maintenance_requests')
            // How the request reached us. Client-created requests are always 'portal';
            // admins set the real channel when logging a request relayed off-portal.
            ->addColumn('source', 'enum', [
                'values' => ['portal', 'whatsapp', 'phone', 'in_person', 'email'],
                'default' => 'portal',
                'null' => false,
                'after' => 'description',
            ])
            // 'internal' records are admin-only notes the client never sees in the portal.
            ->addColumn('visibility', 'enum', [
                'values' => ['client', 'internal'],
                'default' => 'client',
                'null' => false,
                'after' => 'source',
            ])
            // Optional date the record relates to (e.g. when the client called), used to
            // place the record on the attendance calendar. Falls back to created_at.
            ->addColumn('record_date', 'date', [
                'null' => true,
                'after' => 'due_date',
            ])
            ->update();
    }
}
