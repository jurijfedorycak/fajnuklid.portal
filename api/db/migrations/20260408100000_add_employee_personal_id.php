<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add personal_id field to employees table.
 *
 * Used to link an employee with an external time-tracking software record.
 */
final class AddEmployeePersonalId extends AbstractMigration
{
    public function change(): void
    {
        $this->table('employees')
            ->addColumn('personal_id', 'string', [
                'limit' => 50,
                'null' => true,
                'after' => 'phone'
            ])
            ->addIndex(['personal_id'], ['name' => 'idx_personal_id'])
            ->update();
    }
}
