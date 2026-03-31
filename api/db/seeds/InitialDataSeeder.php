<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeds initial test/sample data for Fajnuklid Portal.
 *
 * WARNING: This seeder contains test credentials and should ONLY be run
 * in development/testing environments. Never run in production!
 *
 * Test account credentials:
 *   Email: test@test.cz
 *   Password: test123
 */
class InitialDataSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [];
    }

    public function run(): void
    {
        // Safety check: prevent running in production
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';
        if ($env === 'production') {
            echo "ERROR: InitialDataSeeder cannot be run in production environment!\n";
            return;
        }

        $pdo = $this->getAdapter()->getConnection();

        // Insert Fajnuklid company info
        $this->execute(
            "INSERT INTO company_info (name, ico, dic, address, phone, email, website) VALUES " .
            "('Fajnuklid s.r.o.', '12345678', 'CZ12345678', 'Příkladná 123, 110 00 Praha 1', " .
            "'+420 123 456 789', 'info@fajnuklid.cz', 'https://www.fajnuklid.cz')"
        );

        // Insert sample Fajnuklid contacts
        $this->execute(
            "INSERT INTO fajnuklid_contacts (name, position, phone, email, sort_order, active) VALUES " .
            "('Jan Novák', 'Obchodní ředitel', '+420 777 123 456', 'novak@fajnuklid.cz', 1, 1), " .
            "('Marie Svobodová', 'Zákaznická podpora', '+420 777 234 567', 'podpora@fajnuklid.cz', 2, 1)"
        );

        // Insert sample client
        $this->execute(
            "INSERT INTO clients (client_id, display_name, active) VALUES " .
            "('TEST001', 'Testovací klient s.r.o.', 1)"
        );
        $clientId = (int) $pdo->lastInsertId();

        // Insert sample login account (password: test123)
        $this->execute(
            "INSERT INTO login_accounts (email, password_hash, client_id, portal_enabled) VALUES " .
            "('test@test.cz', '\$2y\$12\$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4p.aOCGn3xSGKqGm', " .
            "{$clientId}, 1)"
        );
        $userId = (int) $pdo->lastInsertId();

        // Insert user settings for the test account
        $this->execute(
            "INSERT INTO user_settings (user_id, notification_email, notification_invoice, notification_attendance) VALUES " .
            "({$userId}, 1, 1, 1)"
        );

        // Insert sample ICO for the test client
        $this->execute(
            "INSERT INTO icos (client_id, ico, name, address, contract_start_date) VALUES " .
            "({$clientId}, '87654321', 'Testovací firma a.s.', 'Testovací 456, 120 00 Praha 2', '2024-01-01')"
        );
        $icoId = (int) $pdo->lastInsertId();

        // Insert sample object
        $this->execute(
            "INSERT INTO objects (ico_id, name, address, latitude, longitude) VALUES " .
            "({$icoId}, 'Hlavní budova', 'Testovací 456, 120 00 Praha 2', 50.0755, 14.4378)"
        );
        $objectId = (int) $pdo->lastInsertId();

        // Insert sample employee
        $this->execute(
            "INSERT INTO employees (first_name, last_name, email, phone, position, show_name, show_photo, show_phone, show_email, active) VALUES " .
            "('Petr', 'Procházka', 'prochazka@fajnuklid.cz', '+420 777 345 678', 'Vedoucí směny', 1, 1, 1, 0, 1)"
        );
        $employeeId = (int) $pdo->lastInsertId();

        // Assign employee to object
        $this->execute(
            "INSERT INTO employee_object_assignments (employee_id, object_id) VALUES " .
            "({$employeeId}, {$objectId})"
        );

        echo "InitialDataSeeder completed successfully.\n";
        echo "Test account: test@test.cz / test123\n";
    }
}
