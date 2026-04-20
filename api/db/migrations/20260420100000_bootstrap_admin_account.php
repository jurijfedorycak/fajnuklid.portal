<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bootstraps the initial admin login_accounts row so the first admin
 * can sign in right after a fresh deployment. Runs exactly once
 * (tracked via phinxlog). The password MUST be rotated immediately
 * after the first successful login.
 *
 * Initial credentials:
 *   Email:    admin@fajnuklid.cz
 *   Password: Admin@Fajn2024
 */
final class BootstrapAdminAccount extends AbstractMigration
{
    private const ADMIN_EMAIL = 'admin@fajnuklid.cz';
    private const ADMIN_PASSWORD_HASH = '$2y$12$GQO5RtHD2sTU.MYBrhbUa.0cTo9hwK4mVZUfZpcQJX153TNCEb.5y';

    public function up(): void
    {
        $this->execute(sprintf(
            "INSERT IGNORE INTO login_accounts (email, password_hash, portal_enabled) VALUES ('%s', '%s', 1)",
            self::ADMIN_EMAIL,
            self::ADMIN_PASSWORD_HASH
        ));
    }

    public function down(): void
    {
        $this->execute(sprintf(
            "DELETE FROM login_accounts WHERE email = '%s'",
            self::ADMIN_EMAIL
        ));
    }
}
