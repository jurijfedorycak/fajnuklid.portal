<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MoveGreetingToLoginAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('login_accounts')
            ->addColumn('greeting', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'email',
                'comment' => 'Optional personalized greeting target, e.g. "pane Nováku"',
            ])
            ->update();

        // Seed each account with its client's existing greeting. A login can in theory
        // span multiple clients with different greetings; MIN() picks one deterministically
        // (a plain UPDATE ... JOIN would pick an arbitrary, optimizer-dependent row).
        $this->execute(
            "UPDATE login_accounts la
             SET la.greeting = (
                 SELECT MIN(cl.greeting)
                 FROM company_users cu
                 JOIN companies c ON c.id = cu.company_id
                 JOIN clients cl ON cl.id = c.client_id
                 WHERE cu.user_id = la.id AND cl.greeting IS NOT NULL AND cl.greeting <> ''
             )
             WHERE EXISTS (
                 SELECT 1
                 FROM company_users cu
                 JOIN companies c ON c.id = cu.company_id
                 JOIN clients cl ON cl.id = c.client_id
                 WHERE cu.user_id = la.id AND cl.greeting IS NOT NULL AND cl.greeting <> ''
             )"
        );

        $this->table('clients')
            ->removeColumn('greeting')
            ->update();
    }

    public function down(): void
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

        // Best-effort reverse: many logins collapse back onto one client, so this is
        // lossy — MIN() keeps one greeting per client and the rest are dropped.
        $this->execute(
            "UPDATE clients cl
             SET cl.greeting = (
                 SELECT MIN(la.greeting)
                 FROM companies c
                 JOIN company_users cu ON cu.company_id = c.id
                 JOIN login_accounts la ON la.id = cu.user_id
                 WHERE c.client_id = cl.id AND la.greeting IS NOT NULL AND la.greeting <> ''
             )
             WHERE EXISTS (
                 SELECT 1
                 FROM companies c
                 JOIN company_users cu ON cu.company_id = c.id
                 JOIN login_accounts la ON la.id = cu.user_id
                 WHERE c.client_id = cl.id AND la.greeting IS NOT NULL AND la.greeting <> ''
             )"
        );

        $this->table('login_accounts')
            ->removeColumn('greeting')
            ->update();
    }
}
