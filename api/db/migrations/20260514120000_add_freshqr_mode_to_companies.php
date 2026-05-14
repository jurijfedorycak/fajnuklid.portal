<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFreshqrModeToCompanies extends AbstractMigration
{
    public function change(): void
    {
        $this->table('companies')
            ->addColumn('freshqr_mode', 'enum', [
                'values' => ['off', 'basic', 'detailed'],
                'default' => 'off',
                'null' => false,
                'after' => 'idoklad_sync_enabled',
                'comment' => 'Per-company FreshQR exposure level: off=no calendar, basic=date+ongoing only, detailed=employee+times',
            ])
            ->update();
    }
}
