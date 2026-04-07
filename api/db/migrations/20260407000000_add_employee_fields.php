<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add additional fields to employees table for portal display options.
 *
 * New fields:
 * - tenure_text: Custom tenure display text
 * - bio: Employee biography
 * - hobbies: Employee hobbies
 * - contract_file: Path to contract file
 * - show_in_portal: Whether to show employee in client portal
 * - show_role: Whether to show position/role
 * - show_hobbies: Whether to show hobbies
 * - show_tenure: Whether to show tenure
 * - show_bio: Whether to show biography
 */
final class AddEmployeeFields extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('employees');

        $table
            ->addColumn('tenure_text', 'string', [
                'limit' => 100,
                'null' => true,
                'after' => 'photo_url',
                'comment' => 'Custom tenure display text',
            ])
            ->addColumn('bio', 'text', [
                'null' => true,
                'after' => 'tenure_text',
                'comment' => 'Employee biography',
            ])
            ->addColumn('hobbies', 'text', [
                'null' => true,
                'after' => 'bio',
                'comment' => 'Employee hobbies',
            ])
            ->addColumn('contract_file', 'string', [
                'limit' => 500,
                'null' => true,
                'after' => 'hobbies',
                'comment' => 'Path to contract file',
            ])
            ->addColumn('show_in_portal', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'show_email',
                'comment' => 'GDPR: show employee in client portal',
            ])
            ->addColumn('show_role', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'show_in_portal',
                'comment' => 'GDPR: show position/role in portal',
            ])
            ->addColumn('show_hobbies', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'show_role',
                'comment' => 'GDPR: show hobbies in portal',
            ])
            ->addColumn('show_tenure', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'show_hobbies',
                'comment' => 'GDPR: show tenure in portal',
            ])
            ->addColumn('show_bio', 'boolean', [
                'default' => false,
                'null' => false,
                'after' => 'show_tenure',
                'comment' => 'GDPR: show biography in portal',
            ])
            ->update();
    }
}
