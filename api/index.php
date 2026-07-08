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

// Pin the runtime timezone to Europe/Prague so every date('Y-m-d') and
// new DateTime('today') call agrees with the business calendar. Without this
// the FreshQR "ongoing today" detection drifts by 1–2 hours near midnight on
// UTC-hosted servers — a cleaning that started after 22:00 Prague time gets
// stamped on tomorrow's date and disappears from the calendar entirely.
date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Europe/Prague'));

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

// Short, collision-unlikely reference stamped on every error response and its
// matching error.log entry, so a "500 with errorId X" seen in the browser can be
// traced to the full stack trace in the log without exposing internals publicly.
function errorReferenceId(): string
{
    try {
        return bin2hex(random_bytes(5));
    } catch (\Throwable $e) {
        return substr(md5(uniqid('', true)), 0, 10);
    }
}

// Whether error responses may carry the real exception detail (message/file/
// line/trace). Always on in development; on in any environment when APP_DEBUG is
// explicitly enabled, so production errors can be diagnosed on demand by flipping
// APP_DEBUG=true in .env — then off again. Kept off by default because the public
// /storage/file endpoint reaches this handler and internals must not leak.
function shouldExposeErrorDetail(): bool
{
    try {
        return Config::get('APP_ENV') === 'development' || Config::getBool('APP_DEBUG');
    } catch (\Throwable $e) {
        return false;
    }
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

    $errorId = errorReferenceId();

    // Fatals bypass set_exception_handler, so log here too — same format so both
    // paths are greppable by errorId in error.log. Logged before the
    // headers_sent() bail so a fatal that strikes mid-output is still recorded.
    $logMessage = date('Y-m-d H:i:s') . " [{$errorId}] FatalError: " . $error['message']
        . ' in ' . $error['file'] . ':' . $error['line'] . "\n\n";
    @file_put_contents(__DIR__ . '/error.log', $logMessage, FILE_APPEND);

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
        'errorId' => $errorId,
    ];

    if (shouldExposeErrorDetail()) {
        $payload['debug'] = [
            'exception' => 'FatalError',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// Promote PHP errors to exceptions so they surface through the handler below,
// with two guards:
//   - Respect error_reporting()/@-suppression: a level the runtime is told to
//     ignore must not be escalated to a fatal exception.
//   - Deprecations are informational about a FUTURE PHP removal, not a bug in
//     the current run, so they're logged and swallowed instead of throwing.
//     Without this, one deprecated call (e.g. a PHP 8.5 stdlib deprecation)
//     turns an otherwise-working endpoint into a 500 — which is exactly how the
//     Docházka overview broke after the production PHP upgrade.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return true;
    }

    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        // Dedup per request: a deprecation fired from inside a loop must not
        // append one log line per iteration and bloat error.log under load.
        static $seenDeprecations = [];
        $key = $message . '|' . $file . '|' . $line;
        if (!isset($seenDeprecations[$key])) {
            $seenDeprecations[$key] = true;
            $logMessage = date('Y-m-d H:i:s') . " [deprecation] {$message} in {$file}:{$line}\n";
            @file_put_contents(__DIR__ . '/error.log', $logMessage, FILE_APPEND);
        }
        return true;
    }

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

    $errorId = errorReferenceId();

    // Log all errors to file for debugging. The errorId ties the browser-visible
    // response to this entry, and the request line gives context for triage.
    $requestLine = ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
    $logMessage = date('Y-m-d H:i:s') . " [{$errorId}] {$requestLine}\n"
        . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n";
    file_put_contents(__DIR__ . '/error.log', $logMessage, FILE_APPEND);

    // Log error in production
    if (Config::get('APP_ENV') !== 'development') {
        error_log("[{$errorId}] " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    $payload = [
        'success' => false,
        'message' => $message,
        'errors' => $errors,
        'errorId' => $errorId,
    ];

    // File paths, class names, and stack traces are exposed only when
    // shouldExposeErrorDetail() allows it — a public unauthenticated endpoint
    // (/storage/file) can reach this handler, so by default (production, APP_DEBUG
    // off) internals stay hidden and only the safe errorId crosses the wire.
    // Flip APP_DEBUG=true in .env to diagnose a live production error, then off.
    if (shouldExposeErrorDetail()) {
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
