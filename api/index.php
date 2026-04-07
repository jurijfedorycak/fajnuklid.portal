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

// Set error handling
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
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

    // Log error in production
    if (Config::get('APP_ENV') !== 'development') {
        error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    Response::json([
        'success' => false,
        'message' => $message,
        'errors' => $errors,
        'debug' => Config::get('APP_ENV') === 'development' ? [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ] : null
    ], $statusCode);
});

// Create request and router
$request = new Request();
$router = new Router($request);

// Load routes
require_once __DIR__ . '/config/routes.php';

// Handle the request
$router->dispatch();
