<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddIdokladSyncEnabledToCompanies extends AbstractMigration
{
    public function change(): void
    {
        $this->table('companies')
            ->addColumn('idoklad_sync_enabled', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'contract_pdf_path',
                'comment' => 'When true, nightly cron pulls issued invoices from iDoklad for this company',
            ])
            ->update();
    }
}
