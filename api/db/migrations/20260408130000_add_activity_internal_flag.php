<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddActivityInternalFlag extends AbstractMigration
{
    public function change(): void
    {
        $this->table('maintenance_request_activity')
            ->addColumn('is_internal', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'status_change',
            ])
            ->update();
    }
}
