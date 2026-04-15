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
        $url = $this->storage->getUrl($key);

        return ['key' => $key, 'url' => $url];
    }

    public function upload(Request $request): void
    {
        $result = $this->processUpload($request);

        Response::success($result, 'Soubor byl nahrán');
    }

    public function getDownloadUrl(Request $request): void
    {
        $key = $request->query('key');

        if (empty($key)) {
            throw new ValidationException('Klíč souboru nebyl zadán');
        }

        if (str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new ValidationException('Neplatný klíč souboru');
        }

        $url = $this->storage->getPresignedUrl($key);

        Response::success(['url' => $url]);
    }

    public function delete(Request $request): void
    {
        $key = $request->input('key');

        if (empty($key)) {
            throw new ValidationException('Klíč souboru nebyl zadán');
        }

        if (str_contains($key, '..') || str_starts_with($key, '/')) {
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
