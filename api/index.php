<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Config;
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthException;
use App\Exceptions\NotFoundException;

// Load configuration
Config::load();

// Helper to send CORS headers (needed for error responses too)
function sendCorsHeaders(): void
{
    $allowedOrigins = Config::getArray('CORS_ALLOWED_ORIGINS');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
    } elseif (count($allowedOrigins) > 0) {
        header("Access-Control-Allow-Origin: {$allowedOrigins[0]}");
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
}

// Set error handling
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    // Send CORS headers for error responses
    sendCorsHeaders();

    $statusCode = 500;
    $message = 'Internal Server Error';
    $errors = null;

    if ($e instanceof ValidationException) {
        $statusCode = 422;
        $message = $e->getMessage();
        $errors = $e->getErrors();
    } elseif ($e instanceof AuthException) {
        $statusCode = 401;
        $message = $e->getMessage();
    } elseif ($e instanceof NotFoundException) {
        $statusCode = 404;
        $message = $e->getMessage();
    } elseif ($e instanceof ApiException) {
        $statusCode = $e->getCode() ?: 400;
        $message = $e->getMessage();
    } elseif ($e instanceof \PDOException && Config::get('APP_ENV') === 'development') {
        $message = 'Database Error: ' . $e->getMessage();
    }

    // Log all errors to file for debugging
    $logMessage = date('Y-m-d H:i:s') . ' ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n";
    file_put_contents(__DIR__ . '/error.log', $logMessage, FILE_APPEND);

    // Log error in production
    if (Config::get('APP_ENV') !== 'development') {
        error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    $payload = [
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ];

    // Never expose file paths, class names, or stack traces to the browser in production —
    // a public unauthenticated endpoint (/storage/file) can reach this handler, so leaking
    // internals here would hand attackers a server-layout reconnaissance tool.
    if (Config::get('APP_ENV') === 'development') {
        $payload['debug'] = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
    }

    Response::json($payload, $statusCode);
});

// Create request and router
$request = new Request();
$router = new Router($request);

// Load routes
require_once __DIR__ . '/config/routes.php';

// Handle the request
$router->dispatch();
