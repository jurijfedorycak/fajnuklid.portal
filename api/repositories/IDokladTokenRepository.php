<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

/**
 * OAuth2 access-token cache, scoped per iDoklad account. Each account
 * (legal entity) authenticates independently and caches its own bearer token
 * under its stable account key.
 */
class IDokladTokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getLatestToken(string $accountKey): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, account_key, access_token, expires_at, created_at
            FROM idoklad_tokens
            WHERE account_key = :account_key
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['account_key' => $accountKey]);

        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function isTokenValid(string $accountKey): bool
    {
        $token = $this->getLatestToken($accountKey);

        if ($token === null) {
            return false;
        }

        $expiresAt = new \DateTime($token['expires_at']);
        $now = new \DateTime();

        // Consider token invalid if it expires in less than 60 seconds
        $now->modify('+60 seconds');

        return $expiresAt > $now;
    }

    public function getValidToken(string $accountKey): ?string
    {
        if (!$this->isTokenValid($accountKey)) {
            return null;
        }

        $token = $this->getLatestToken($accountKey);
        return $token['access_token'] ?? null;
    }

    public function saveToken(string $accountKey, string $accessToken, \DateTimeInterface $expiresAt): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO idoklad_tokens (account_key, access_token, expires_at, created_at)
            VALUES (:account_key, :access_token, :expires_at, NOW())
        ');

        $stmt->execute([
            'account_key' => $accountKey,
            'access_token' => $accessToken,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteExpiredTokens(): int
    {
        $stmt = $this->db->prepare('
            DELETE FROM idoklad_tokens
            WHERE expires_at < NOW()
        ');
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function deleteAllTokens(string $accountKey): int
    {
        $stmt = $this->db->prepare('
            DELETE FROM idoklad_tokens
            WHERE account_key = :account_key
        ');
        $stmt->execute(['account_key' => $accountKey]);

        return $stmt->rowCount();
    }
}
