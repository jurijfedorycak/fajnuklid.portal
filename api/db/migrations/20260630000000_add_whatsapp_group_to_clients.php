<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddWhatsappGroupToClients extends AbstractMigration
{
    public function change(): void
    {
        $this->table('clients')
            ->addColumn('whatsapp_group_url', 'string', [
                'limit' => 500,
                'null' => true,
                'default' => null,
                'after' => 'greeting',
                'comment' => 'WhatsApp group invite link (https://chat.whatsapp.com/...) shown to the client in the portal',
            ])
            ->update();
    }
}
