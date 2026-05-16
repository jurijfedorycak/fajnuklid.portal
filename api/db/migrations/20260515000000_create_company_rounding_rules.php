<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCompanyRoundingRules extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('company_rounding_rules', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('company_id', 'integer', [
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('threshold_minutes', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Lower bound of the rule range in minutes; rule applies to durations >= this and < the next rule threshold',
            ])
            ->addColumn('interval_minutes', 'integer', [
                'signed' => false,
                'null' => false,
                'comment' => 'Rounding step in minutes; 0 means no rounding (direction must be "none")',
            ])
            ->addColumn('direction', 'enum', [
                'values' => ['up', 'down', 'nearest', 'none'],
                'null' => false,
                'comment' => 'Rounding direction; "none" leaves the duration unchanged within this range',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['company_id', 'threshold_minutes'], [
                'unique' => true,
                'name' => 'uk_company_threshold',
            ])
            ->addForeignKey('company_id', 'companies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_rounding_rules_company',
            ])
            ->create();
    }
}
