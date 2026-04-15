<?php

declare(strict_types=1);

namespace App\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use App\Config\Config;

class R2StorageService
{
    private ?S3Client $client;
    private ?string $bucket = null;
    private ?string $publicUrl = null;
    private bool $initialized = false;

    public function __construct(?S3Client $client = null, ?string $bucket = null, ?string $publicUrl = null)
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->publicUrl = $publicUrl;

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
     */
    public function getUrl(string $key): string
    {
        $this->ensureInitialized();

        return $this->getPublicUrl($key) ?? $this->getPresignedUrl($key);
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
