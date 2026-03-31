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
 *   Email: admin@fajnuklid.cz
 *   Password: Admin@Fajn2024
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

        // Insert test login account (password: test123)
        $this->execute(
            "INSERT INTO login_accounts (email, password_hash, portal_enabled) VALUES " .
            "('admin@fajnuklid.cz', '\$2y\$12\$wPAF6bI50896gazx504PTeumPfcik4WzTnTsMOh3aia0Isr0yZsTO', 1)"
        );

        echo "InitialDataSeeder completed successfully.\n";
        echo "Test account: admin@fajnuklid.cz / Admin@Fajn2024\n";
    }
}
