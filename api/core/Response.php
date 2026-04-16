<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    public static function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        if ($data !== null) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, ?array $errors = null): void
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }

    public static function created(mixed $data = null, string $message = 'Created'): void
    {
        self::success($data, $message, 201);
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    public static function paginated(array $items, int $total, int $page, int $perPage): void
    {
        $lastPage = (int) ceil($total / $perPage);

        self::json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total
            ]
        ]);
    }

    public static function file(string $content, string $filename, string $contentType): void
    {
        // Sanitize filename to prevent header injection
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($filename));
        if (empty($safeFilename)) {
            $safeFilename = 'download';
        }

        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        echo $content;
        exit;
    }

    public static function pdf(string $content, string $filename): void
    {
        self::file($content, $filename, 'application/pdf');
    }

    /**
     * Stream a binary payload to the browser with inline (or attachment) disposition.
     * Used by the R2 proxy endpoint so that `<img src>` and download links resolve to
     * stable URLs that return the underlying bytes directly.
     *
     * `immutable` is deliberately NOT set — admins can remove a file, so browsers
     * must be allowed to re-validate. `max-age=300` gives a five-minute cache to
     * keep list views snappy without letting deleted photos linger for a day.
     */
    public static function stream(
        string $content,
        string $contentType,
        ?string $filename = null,
        bool $asAttachment = false,
        int $cacheMaxAge = 300
    ): void {
        $contentType = self::sanitizeContentType($contentType);

        http_response_code(200);
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, max-age=' . $cacheMaxAge);
        header('X-Content-Type-Options: nosniff');
        // Make <img> and <iframe> work when FE and API live on different origins, and
        // ensure shared caches key responses per-Origin rather than leaking CORS headers.
        header('Cross-Origin-Resource-Policy: cross-origin');
        header('Vary: Origin');

        if ($filename !== null) {
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($filename));
            if ($safeFilename === '' || $safeFilename === null) {
                $safeFilename = 'file';
            }
            $disposition = $asAttachment ? 'attachment' : 'inline';
            header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
        }

        echo $content;
        exit;
    }

    /**
     * Reject anything that doesn't look like a RFC-compliant media type so an
     * attacker-controlled ContentType cannot inject extra response headers
     * (CR/LF/other bytes) via `header()`. Falls back to application/octet-stream
     * when validation fails. Delimiter is ~ because the character class
     * legitimately contains #.
     */
    public static function sanitizeContentType(string $contentType): string
    {
        $pattern = '~^[a-zA-Z0-9][a-zA-Z0-9!#$&\-^_.+]*/[a-zA-Z0-9!#$&\-^_.+]+(?:\s*;\s*[a-zA-Z0-9!#$&\-^_.+]+=[a-zA-Z0-9!#$&\-^_.+"]+)*$~';
        return preg_match($pattern, $contentType) === 1 ? $contentType : 'application/octet-stream';
    }
}
