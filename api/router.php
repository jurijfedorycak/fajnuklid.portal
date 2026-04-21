<?php

/**
 * Router script for PHP built-in server.
 * Usage: php -S 127.0.0.1:8001 router.php
 *
 * Bind to 127.0.0.1 explicitly — on Windows `php -S localhost:8001` often
 * resolves to ::1 only, and Chrome/Edge resolve `localhost` to 127.0.0.1,
 * producing ERR_CONNECTION_REFUSED that browsers mislabel as a CORS failure.
 */

// Serve static files directly
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

if ($uri !== '/' && file_exists($path) && is_file($path)) {
    return false; // Let PHP serve the file directly
}

// Route everything else through index.php
require_once __DIR__ . '/index.php';
