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

// Helper to send CORS headers (needed for error responses too).
// Wrapped in a try/catch so that if Config itself failed to load (e.g. a fatal
// in .env parsing), the shutdown hook can still surface an Allow-Origin echo
// of the request origin rather than leaving the FE with a bare CORS failure.
function sendCorsHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    try {
        $allowedOrigins = Config::getArray('CORS_ALLOWED_ORIGINS');

        if (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        } elseif (count($allowedOrigins) > 0) {
            header("Access-Control-Allow-Origin: {$allowedOrigins[0]}");
        }
    } catch (\Throwable $e) {
        if ($origin !== '') {
            header("Access-Control-Allow-Origin: {$origin}");
        }
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
}

// Fatal errors (max_execution_time, OOM, parse/compile errors) bypass
// set_exception_handler entirely — the script dies without the JSON response
// body or CORS headers the FE needs, so the browser reports a generic CORS
// failure instead of the real cause. The shutdown hook ensures the FE always
// gets a same-origin response even in the fatal case.
register_shutdown_function(function () {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error === null || !in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    sendCorsHeaders();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'success' => false,
        'message' => 'Internal Server Error',
        'errors' => null,
    ];

    if (Config::get('APP_ENV') === 'development') {
        $payload['debug'] = [
            'exception' => 'FatalError',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

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
