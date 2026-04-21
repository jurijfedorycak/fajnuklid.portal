<?php

/**
 * Phinx Configuration
 *
 * Loads database settings from .env file for consistent configuration.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Local dev loads config via phpdotenv into $_ENV (getenv stays empty because
// createImmutable doesn't call putenv). GitHub CI sets real process env vars,
// which populate getenv but not $_ENV (PHP CLI variables_order defaults to
// GPCS). Check both so Phinx works in either environment — and coerce to
// ?string so its adapter typehints don't blow up on bool(false).
$phinxEnv = static function (string $key): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === '') ? null : (string) $value;
};

$dbHost = $phinxEnv('DB_HOST');
$dbName = $phinxEnv('DB_DATABASE');
$dbUser = $phinxEnv('DB_USERNAME');
$dbPass = $phinxEnv('DB_PASSWORD');
$dbPort = $phinxEnv('DB_PORT');

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'port' => $dbPort,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'development' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'port' => $dbPort,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
        'testing' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $dbName . '_test',
            'user' => $dbUser,
            'pass' => $dbPass,
            'port' => $dbPort,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
