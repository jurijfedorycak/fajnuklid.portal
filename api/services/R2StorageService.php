<?php

declare(strict_types=1);

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use App\Config\Config;
use App\Exceptions\NotFoundException;

class R2StorageService
{
    /**
     * Domain-separation prefix so the HMAC used for proxy-URL signing cannot collide
     * with any other HMAC usage of JWT_SECRET (e.g. the JWT itself).
     */
    private const PROXY_SIG_PREFIX = 'r2-proxy:';

    private ?S3Client $client;
    private ?string $bucket = null;
    private ?string $publicUrl = null;
    private ?string $proxyBaseUrl = null;
    private ?string $proxySecret = null;
    private bool $initialized = false;

    public function __construct(
        ?S3Client $client = null,
        ?string $bucket = null,
        ?string $publicUrl = null,
        ?string $proxyBaseUrl = null,
        ?string $proxySecret = null
    ) {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->publicUrl = $publicUrl;
        $this->proxyBaseUrl = $proxyBaseUrl;
        $this->proxySecret = $proxySecret;

        if ($client !== null) {
            $this->initialized = true;
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        Config::load();

        $this->bucket = $this->bucket ?? Config::getRequired('R2_BUCKET_NAME');
        $this->publicUrl = $this->publicUrl ?? (Config::get('R2_PUBLIC_URL') ?: null);
        $this->proxyBaseUrl = $this->proxyBaseUrl ?? (Config::get('APP_URL') ?: null);
        $this->proxySecret = $this->proxySecret ?? (Config::get('JWT_SECRET') ?: null);

        if ($this->client === null) {
            $accountId = Config::getRequired('R2_ACCOUNT_ID');
            $accessKey = Config::getRequired('R2_ACCESS_KEY_ID');
            $secretKey = Config::getRequired('R2_SECRET_ACCESS_KEY');

            $this->client = new S3Client([
                'region' => 'auto',
                'version' => 'latest',
                'endpoint' => "https://{$accountId}.r2.cloudflarestorage.com",
                'credentials' => [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ],
                'use_path_style_endpoint' => true,
            ]);
        }

        $this->initialized = true;
    }

    /**
     * Upload a file to R2.
     *
     * @return string The storage key (e.g. "employee-photos/photo_abc123.jpg")
     */
    public function upload(string $folder, string $tmpFilePath, string $originalName, string $mimeType): string
    {
        $this->ensureInitialized();

        $key = self::generateKey($folder, $originalName);

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $tmpFilePath,
                'ContentType' => $mimeType,
            ]);
        } catch (AwsException $e) {
            error_log('R2 upload failed: ' . $e->getMessage());
            throw new \RuntimeException('Nepodařilo se nahrát soubor do úložiště.');
        }

        return $key;
    }

    /**
     * Generate a presigned download URL valid for $expirySeconds.
     *
     * @deprecated Prefer getProxyUrl() — URLs it returns are stable and never expire,
     *             which is what every caller actually wants.
     */
    public function getPresignedUrl(string $key, int $expirySeconds = 3600): string
    {
        $this->ensureInitialized();

        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, "+{$expirySeconds} seconds");

        return (string) $request->getUri();
    }

    /**
     * Return the public URL if R2_PUBLIC_URL is configured, null otherwise.
     *
     * @deprecated Our buckets are private — use getProxyUrl() instead.
     */
    public function getPublicUrl(string $key): ?string
    {
        $this->ensureInitialized();

        if ($this->publicUrl === null) {
            return null;
        }

        return rtrim($this->publicUrl, '/') . '/' . ltrim($key, '/');
    }

    /**
     * Convenience: public URL if available, else presigned URL.
     *
     * @deprecated Prefer getProxyUrl() — stable, never-expiring, works cross-origin.
     */
    public function getUrl(string $key): string
    {
        $this->ensureInitialized();

        return $this->getPublicUrl($key) ?? $this->getPresignedUrl($key);
    }

    /**
     * Produce a fresh URL for a stored file reference. Accepts either an R2 key,
     * a legacy /uploads/... local path, or a legacy full R2 URL (public or presigned)
     * persisted before storage-key normalization. Presigned URLs expire; calling this
     * on read guarantees the returned URL is always valid.
     *
     * @deprecated Prefer resolveProxyUrl() — the stable proxy URL never expires, so
     *             FE code that keeps the URL in state or DOM doesn't break over time.
     */
    public function resolveUrl(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (str_starts_with($stored, '/uploads/')) {
            return $stored;
        }

        $key = $this->extractKey($stored);
        if ($key === '') {
            return null;
        }

        return $this->getUrl($key);
    }

    /**
     * Produce a stable, never-expiring proxy URL for a given R2 key.
     *
     * The URL is HMAC-signed with JWT_SECRET so it cannot be forged without the secret,
     * yet it works directly in <img src> and <a href> because the signature itself is
     * the authentication — no session cookie or Authorization header needed. Since the
     * HMAC is deterministic per key, the URL for a given stored file never changes,
     * so it can be cached by the browser forever and will never fail mid-view.
     *
     * Base URL is derived from the current request unless APP_URL is explicitly set
     * (useful when the API is hit from multiple hostnames and we want to pin one).
     */
    public function getProxyUrl(string $key): string
    {
        $this->ensureInitialized();

        $base = $this->resolveBaseUrl();
        $sig = $this->signKey($key);

        return $base . '/storage/file?key=' . rawurlencode($key) . '&sig=' . $sig;
    }

    /**
     * Compute the public-facing base URL for proxy links.
     *
     * Explicit APP_URL config wins. Otherwise derive from $_SERVER: HTTP_HOST (the host
     * the client addressed) plus the configured API_PREFIX, with the scheme inferred
     * from several signals so TLS-terminated reverse proxies still emit https URLs.
     *
     * Host-header spoofing does not grant privilege here — the HMAC signature is bound
     * to the key, not the host, so a forged Host just returns the attacker's own URL
     * back to the attacker. The only scenario worth pinning APP_URL for is a
     * deployment where the API is reached from multiple hostnames and responses could
     * be reused across them (rare).
     */
    private function resolveBaseUrl(): string
    {
        if ($this->proxyBaseUrl !== null && $this->proxyBaseUrl !== '') {
            return rtrim($this->proxyBaseUrl, '/');
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException(
                'Nelze určit veřejnou URL API — nastavte APP_URL nebo zajistěte HTTP Host hlavičku.'
            );
        }

        $scheme = self::requestIsHttps() ? 'https' : 'http';
        $prefix = rtrim((string) Config::get('API_PREFIX', '/api'), '/');

        return $scheme . '://' . $host . $prefix;
    }

    private static function requestIsHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strcasecmp((string) $_SERVER['HTTPS'], 'off') !== 0) {
            return true;
        }
        if (strcasecmp((string) ($_SERVER['REQUEST_SCHEME'] ?? ''), 'https') === 0) {
            return true;
        }
        if (((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443) {
            return true;
        }
        // Terminating proxy signal — trusted because spoofing it only changes what
        // scheme our own emitted URLs use, and the URLs are per-key HMAC-signed.
        if (strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') === 0) {
            return true;
        }
        return false;
    }

    /**
     * Produce a proxy URL from whatever is currently stored on a DB row — a storage
     * key, a legacy /uploads/... path, or a legacy full R2 URL from before we
     * normalized columns to bare keys. Returns null for empty input so callers don't
     * accidentally emit `<img src="">` (which re-requests the current page).
     */
    public function resolveProxyUrl(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (str_starts_with($stored, '/uploads/')) {
            $this->ensureInitialized();
            return $this->resolveBaseUrl() . $stored;
        }

        $key = $this->extractKey($stored);
        if ($key === '') {
            return null;
        }

        return $this->getProxyUrl($key);
    }

    /**
     * Compute the HMAC signature for a storage key. Constant-time verification is
     * done via hash_equals in verifyKeySignature.
     */
    public function signKey(string $key): string
    {
        $this->ensureInitialized();

        if ($this->proxySecret === null || $this->proxySecret === '') {
            throw new \RuntimeException('Proxy URL signing secret is not configured.');
        }

        return hash_hmac('sha256', self::PROXY_SIG_PREFIX . $key, $this->proxySecret);
    }

    /**
     * Verify that a signature was produced by signKey for the same key — constant-time.
     */
    public function verifyKeySignature(string $key, string $sig): bool
    {
        if ($sig === '') {
            return false;
        }

        return hash_equals($this->signKey($key), $sig);
    }

    /**
     * Normalize a stored value to a bare R2 key. Handles three URL shapes:
     *  - our own proxy URL (…/storage/file?key=<encoded-key>&sig=…) — pulls from ?key=
     *  - legacy R2 presigned URLs (path-style, embedding the bucket name) — strips bucket
     *  - legacy R2 public URLs — returns the path as-is
     *
     * Non-URL input is returned unchanged so bare keys round-trip cleanly.
     */
    public function extractKey(string $stored): string
    {
        if (!preg_match('~^https?://~i', $stored)) {
            return $stored;
        }

        $this->ensureInitialized();

        // Our own stable proxy URL carries the key in the query string — use it verbatim
        // so we don't misread /storage/file as the key itself.
        $query = parse_url($stored, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            if (!empty($params['key']) && is_string($params['key'])) {
                return $params['key'];
            }
        }

        $path = parse_url($stored, PHP_URL_PATH) ?? '';
        $path = ltrim($path, '/');

        if ($this->bucket !== null && $this->bucket !== '' && str_starts_with($path, $this->bucket . '/')) {
            $path = substr($path, strlen($this->bucket) + 1);
        }

        return $path;
    }

    /**
     * Fetch file content from R2 (for proxy downloads).
     */
    public function getContent(string $key): string
    {
        $this->ensureInitialized();

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (AwsException $e) {
            error_log('R2 download failed: ' . $e->getMessage());
            throw new \RuntimeException('Nepodařilo se stáhnout soubor z úložiště.');
        }

        return (string) $result['Body'];
    }

    /**
     * Fetch content + metadata from R2 in one call — used by the proxy endpoint so the
     * response carries the correct Content-Type captured at upload time.
     *
     * @return array{body: string, contentType: string}
     * @throws NotFoundException When the key does not exist in R2.
     */
    public function getObjectWithMeta(string $key): array
    {
        $this->ensureInitialized();

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (AwsException $e) {
            // Map missing-object to 404 so the global handler returns the right status.
            if ($e->getAwsErrorCode() === 'NoSuchKey') {
                throw new NotFoundException('Soubor nebyl nalezen');
            }
            error_log('R2 download failed: ' . $e->getMessage());
            throw new \RuntimeException('Nepodařilo se stáhnout soubor z úložiště.');
        }

        return [
            'body' => (string) $result['Body'],
            'contentType' => (string) ($result['ContentType'] ?? 'application/octet-stream'),
        ];
    }

    /**
     * Delete an object from R2. Silently succeeds if the key doesn't exist.
     */
    public function delete(string $key): void
    {
        $this->ensureInitialized();

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (AwsException $e) {
            error_log('R2 delete failed: ' . $e->getMessage());
            throw new \RuntimeException('Nepodařilo se smazat soubor z úložiště.');
        }
    }

    /**
     * Generate a sanitized storage key: {folder}/{safeName}_{uniqid}.{ext}
     */
    public static function generateKey(string $folder, string $originalName): string
    {
        $originalName = substr(str_replace("\0", '', $originalName), 0, 255);
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($originalName, PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';
        $uniqueId = bin2hex(random_bytes(8));

        return $folder . '/' . $safeName . '_' . $uniqueId . ($extension ? '.' . $extension : '');
    }
}
