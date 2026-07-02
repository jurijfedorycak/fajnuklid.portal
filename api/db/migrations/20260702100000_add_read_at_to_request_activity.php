<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddReadAtToRequestActivity extends AbstractMigration
{
    public function change(): void
    {
        $this->table('maintenance_request_activity')
            // When the counterparty read the message — for admin-authored entries this is
            // the client opening the request detail. Drives "N nové zprávy" in the list.
            ->addColumn('read_at', 'datetime', [
                'null' => true,
                'after' => 'is_internal',
            ])
            ->update();
    }
}
