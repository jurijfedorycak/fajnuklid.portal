<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->safeLoad();

        self::$config = $_ENV;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $_ENV[$key] ?? $default;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException("Required configuration '{$key}' is not set");
        }

        return (string) $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }

    public static function getArray(string $key, string $delimiter = ','): array
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return [];
        }

        return array_map('trim', explode($delimiter, (string) $value));
    }
}
