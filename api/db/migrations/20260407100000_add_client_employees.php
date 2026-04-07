<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddClientEmployees extends AbstractMigration
{
    public function change(): void
    {
        // Create client_employees junction table
        // Links employees directly to clients (staff assignment)
        // employee_locations remains for optional granular location assignments
        $this->table('client_employees', ['signed' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('employee_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['client_id', 'employee_id'], ['unique' => true, 'name' => 'uk_client_employee'])
            ->addIndex(['employee_id'], ['name' => 'idx_employee_id'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('employee_id', 'employees', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
