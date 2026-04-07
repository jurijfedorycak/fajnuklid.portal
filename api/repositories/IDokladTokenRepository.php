<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use PDO;

class IDokladTokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getLatestToken(): ?array
    {
        $stmt = $this->db->query('
            SELECT id, access_token, expires_at, created_at
            FROM idoklad_tokens
            ORDER BY id DESC
            LIMIT 1
        ');

        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function isTokenValid(): bool
    {
        $token = $this->getLatestToken();

        if ($token === null) {
            return false;
        }

        $expiresAt = new \DateTime($token['expires_at']);
        $now = new \DateTime();

        // Consider token invalid if it expires in less than 60 seconds
        $now->modify('+60 seconds');

        return $expiresAt > $now;
    }

    public function getValidToken(): ?string
    {
        if (!$this->isTokenValid()) {
            return null;
        }

        $token = $this->getLatestToken();
        return $token['access_token'] ?? null;
    }

    public function saveToken(string $accessToken, \DateTimeInterface $expiresAt): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO idoklad_tokens (access_token, expires_at, created_at)
            VALUES (:access_token, :expires_at, NOW())
        ');

        $stmt->execute([
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

    public function deleteAllTokens(): int
    {
        $stmt = $this->db->query('DELETE FROM idoklad_tokens');
        return $stmt->rowCount();
    }
}
