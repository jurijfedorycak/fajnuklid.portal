<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Config;
use App\Core\Request;

class CorsMiddleware
{
    public function handle(Request $request): void
    {
        $allowedOrigins = Config::getArray('CORS_ALLOWED_ORIGINS');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check if origin is allowed
        if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } elseif (count($allowedOrigins) > 0) {
            // Default to first allowed origin if no match
            header("Access-Control-Allow-Origin: {$allowedOrigins[0]}");
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }
}
