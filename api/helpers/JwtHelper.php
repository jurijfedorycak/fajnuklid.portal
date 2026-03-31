<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Throwable;

class JwtHelper
{
    private const ALGORITHM = 'HS256';

    public static function encode(array $payload): string
    {
        $secret = Config::getRequired('JWT_SECRET');
        $expiresIn = Config::getInt('JWT_EXPIRES_IN', 86400);

        $issuedAt = time();
        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $issuedAt + $expiresIn
        ]);

        return JWT::encode($payload, $secret, self::ALGORITHM);
    }

    public static function decode(string $token): ?array
    {
        try {
            $secret = Config::getRequired('JWT_SECRET');
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));

            return (array) $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function createForUser(int $userId, string $email): string
    {
        return self::encode([
            'sub' => $userId,
            'email' => $email
        ]);
    }
}
