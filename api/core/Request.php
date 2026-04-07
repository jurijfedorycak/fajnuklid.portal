<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    private array $body;
    private array $query;
    private array $params = [];
    private array $headers;
    private ?array $user = null;

    public function __construct()
    {
        $this->body = $this->parseBody();
        $this->query = $_GET;
        $this->headers = $this->parseHeaders();
    }

    private function parseBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            $decoded = json_decode($rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }

        // Handle Authorization header specially (Apache workaround)
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }

    public function getMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    public function getPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = $path ?: '/';

        // Strip /api prefix if present (API is served at /api path)
        if (str_starts_with($path, '/api')) {
            $path = substr($path, 4) ?: '/';
        }

        return $path;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        $data = $this->all();
        return array_intersect_key($data, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtoupper(str_replace('-', '_', $name));
        return $this->headers[$name] ?? $this->headers[str_replace('_', '-', $name)] ?? null;
    }

    public function getBearerToken(): ?string
    {
        $authorization = $this->getHeader('Authorization');

        if ($authorization && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return null;
    }

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getUserId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    public function getClientId(): ?int
    {
        return $this->user['client_id'] ?? null;
    }

    public function isAdmin(): bool
    {
        return $this->user['is_admin'] ?? false;
    }

    public function getIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
