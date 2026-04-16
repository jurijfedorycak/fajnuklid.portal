<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\R2StorageService;
use App\Exceptions\ValidationException;

class StorageController extends Controller
{
    private R2StorageService $storage;

    private const FOLDER_RULES = [
        'employee-photos' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'maxSize' => 2 * 1024 * 1024,
        ],
        'employee-contracts' => [
            'mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'maxSize' => 10 * 1024 * 1024,
        ],
        'staff-contacts' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'maxSize' => 2 * 1024 * 1024,
        ],
        'maintenance-request-attachments' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'],
            'maxSize' => 10 * 1024 * 1024,
        ],
    ];

    public function __construct()
    {
        $this->storage = new R2StorageService();
    }

    /**
     * Upload a file to R2 and return the result without sending an HTTP response.
     *
     * @return array{key: string, url: string}
     */
    public function processUpload(Request $request): array
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Soubor nebyl nahrán nebo došlo k chybě při nahrávání');
        }

        $file = $_FILES['file'];
        $folder = $request->input('folder', '');

        if (!isset(self::FOLDER_RULES[$folder])) {
            throw new ValidationException('Neplatná složka pro nahrání');
        }

        $rules = self::FOLDER_RULES[$folder];

        if ($file['size'] > $rules['maxSize']) {
            $maxMb = $rules['maxSize'] / 1024 / 1024;
            throw new ValidationException("Soubor je příliš velký. Maximum: {$maxMb} MB");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $rules['mimes'], true)) {
            throw new ValidationException('Nepodporovaný typ souboru');
        }

        $key = $this->storage->upload($folder, $file['tmp_name'], $file['name'], $mimeType);
        $url = $this->storage->getProxyUrl($key);

        return ['key' => $key, 'url' => $url];
    }

    public function upload(Request $request): void
    {
        $result = $this->processUpload($request);

        Response::success($result, 'Soubor byl nahrán');
    }

    public function getDownloadUrl(Request $request): void
    {
        $raw = $request->query('key');

        if (empty($raw)) {
            throw new ValidationException('Klíč souboru nebyl zadán');
        }

        // Accept either a bare storage key or a legacy URL — normalize to a key.
        $key = $this->storage->extractKey((string) $raw);

        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        // Hand back the stable, HMAC-signed proxy URL rather than a short-lived
        // presigned R2 URL — FE can use it as <img src> or href indefinitely.
        $url = $this->storage->getProxyUrl($key);

        Response::success(['url' => $url]);
    }

    /**
     * Public proxy endpoint. Streams an R2 object to the browser if the HMAC
     * signature matches. Usable directly in <img src>, <a href>, etc. — the URL
     * the API hands out is stable forever, so DOM nodes never go stale.
     *
     * Security model:
     *   - The sig is HMAC-SHA256(key, JWT_SECRET). Without the secret a signature
     *     cannot be forged, so the signature itself is the authentication.
     *   - Keys include 16 random hex chars on generation (2^64 space), so enumeration
     *     is infeasible — and enumeration alone doesn't help without the sig.
     *   - Key is length-capped and charset-restricted before it reaches R2 to stop
     *     null-byte, array, or oversized inputs from wasting HMAC cycles or tripping
     *     AWS SDK edge cases.
     *   - Signature verified before the folder check so an attacker without the
     *     secret cannot use error-message differences to probe valid folder names.
     */
    public function serveFile(Request $request): void
    {
        $rawKey = $request->query('key');
        $rawSig = $request->query('sig');

        if (is_array($rawKey) || is_array($rawSig)) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        $key = (string) ($rawKey ?? '');
        $sig = (string) ($rawSig ?? '');

        if ($key === '' || $sig === '') {
            throw new ValidationException('Chybí parametry souboru');
        }

        if (!self::isValidStorageKey($key)) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        // HMAC first — ensures an attacker without the secret gets the same error
        // regardless of whether the folder happens to exist.
        if (!$this->storage->verifyKeySignature($key, $sig)) {
            throw new ValidationException('Neplatný podpis souboru');
        }

        $folder = explode('/', $key, 2)[0] ?? '';
        if (!isset(self::FOLDER_RULES[$folder])) {
            throw new ValidationException('Neplatná složka');
        }

        $object = $this->storage->getObjectWithMeta($key);

        $asAttachment = $request->query('dl') === '1';
        Response::stream(
            $object['body'],
            $object['contentType'],
            basename($key),
            $asAttachment
        );
    }

    /**
     * Defense-in-depth validation for storage keys accepted via public query params.
     * Matches the key shape generateKey() emits (folder/name_{16hex}.ext) and keeps the
     * surface small: ASCII-only, no traversal, bounded length.
     */
    private static function isValidStorageKey(string $key): bool
    {
        if (strlen($key) > 512) {
            return false;
        }
        if (str_contains($key, "\0") || str_contains($key, '..') || str_starts_with($key, '/')) {
            return false;
        }
        return (bool) preg_match('#^[a-zA-Z0-9_\-./]+$#', $key);
    }

    public function delete(Request $request): void
    {
        $raw = $request->input('key');

        if (empty($raw)) {
            throw new ValidationException('Klíč souboru nebyl zadán');
        }

        $key = $this->storage->extractKey((string) $raw);

        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        $folder = explode('/', $key, 2)[0] ?? '';
        if (!isset(self::FOLDER_RULES[$folder])) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        $this->storage->delete($key);

        Response::noContent();
    }
}
