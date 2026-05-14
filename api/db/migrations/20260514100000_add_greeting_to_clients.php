<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddGreetingToClients extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clients')
            ->addColumn('greeting', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'display_name',
                'comment' => 'Optional personalized greeting target, e.g. "pane Nováku"',
            ])
            ->update();
    }
}
