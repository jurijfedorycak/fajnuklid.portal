<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIsDemoToClients extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clients')
            ->addColumn('is_demo', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'display_name',
            ])
            ->update();
    }
}
