<?php

/**
 * Router script for PHP built-in server.
 * Usage: php -S localhost:8001 router.php
 */

// Serve static files directly
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

if ($uri !== '/' && file_exists($path) && is_file($path)) {
    return false; // Let PHP serve the file directly
}

// Route everything else through index.php
require_once __DIR__ . '/index.php';
