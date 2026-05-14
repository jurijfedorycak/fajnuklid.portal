<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBillingModelToCompanies extends AbstractMigration
{
    public function change(): void
    {
        $this->table('companies')
            ->addColumn('billing_model', 'enum', [
                'values' => ['hourly', 'fixed'],
                'default' => null,
                'null' => true,
                'after' => 'freshqr_mode',
                'comment' => 'Per-company billing model shown in attendance view: hourly=Hodinová sazba, fixed=Paušál, NULL=not set',
            ])
            ->update();
    }
}
