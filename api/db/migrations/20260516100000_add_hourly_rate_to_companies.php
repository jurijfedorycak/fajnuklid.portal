<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddHourlyRateToCompanies extends AbstractMigration
{
    public function change(): void
    {
        $this->table('companies')
            ->addColumn('hourly_rate', 'decimal', [
                'precision' => 10,
                'scale' => 2,
                'null' => true,
                'default' => null,
                'after' => 'billing_model',
                'comment' => 'Hourly rate in CZK shown in attendance view when billing_model=hourly; NULL when not set or when billing_model is fixed/Neurčeno',
            ])
            ->update();
    }
}
