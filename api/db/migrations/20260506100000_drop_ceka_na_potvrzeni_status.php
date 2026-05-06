<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropCekaNaPotvrzeniStatus extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("UPDATE `maintenance_requests` SET `status` = 'resi_se' WHERE `status` = 'ceka_na_potvrzeni'");

        $this->execute(
            "ALTER TABLE `maintenance_requests`
                MODIFY `status` ENUM('prijato','resi_se','vyreseno','zablokovano') NOT NULL DEFAULT 'prijato'"
        );
    }

    public function down(): void
    {
        $this->execute(
            "ALTER TABLE `maintenance_requests`
                MODIFY `status` ENUM('prijato','resi_se','ceka_na_potvrzeni','vyreseno','zablokovano') NOT NULL DEFAULT 'prijato'"
        );
    }
}
