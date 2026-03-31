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
}
