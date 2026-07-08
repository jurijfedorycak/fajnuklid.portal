<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanyDocumentRepository;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class CompanyDocumentService
{
    public const STORAGE_FOLDER = 'company-documents';
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_TYPE_LENGTH = 100;

    /**
     * Accepted upload MIME types: PDF, Word, Excel and common image scans. Mirrors the
     * folder rule registered in StorageController so the public proxy endpoint and this
     * service agree on what a "company document" may be.
     */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
    ];

    private CompanyDocumentRepository $repo;
    private R2StorageService $storage;

    public function __construct(
        ?CompanyDocumentRepository $repo = null,
        ?R2StorageService $storage = null
    ) {
        $this->repo = $repo ?? new CompanyDocumentRepository();
        $this->storage = $storage ?? new R2StorageService();
    }

    /**
     * @return array<int, array> Formatted documents for a single company.
     */
    public function listForCompany(int $companyId, bool $withUrl = false): array
    {
        return array_map(
            fn (array $row) => $this->formatRow($row, $withUrl),
            $this->repo->findByCompanyId($companyId)
        );
    }

    /**
     * Batch variant for the client portal: formatted documents grouped by company id.
     *
     * @param array<int,int> $companyIds
     * @return array<int, array<int, array>> Keyed by company id.
     */
    public function listForCompaniesGrouped(array $companyIds, bool $withUrl = false): array
    {
        $grouped = [];
        foreach ($companyIds as $cid) {
            $grouped[(int) $cid] = [];
        }

        foreach ($this->repo->findByCompanyIds($companyIds) as $row) {
            $grouped[(int) $row['company_id']][] = $this->formatRow($row, $withUrl);
        }

        return $grouped;
    }

    /**
     * Number of documents attached to a company. Cheaper than listForCompany() when the
     * caller only needs presence/count (e.g. the dashboard "Smlouva" status card).
     */
    public function countForCompany(int $companyId): int
    {
        return $this->repo->countByCompanyId($companyId);
    }

    public function upload(int $companyId, ?int $userId, array $file, mixed $title, mixed $type): array
    {
        $title = $this->normaliseTitle($title);
        $documentType = $this->normaliseType($type);
        $mime = $this->validateFile($file);

        $key = $this->storage->upload(
            self::STORAGE_FOLDER,
            $file['tmp_name'],
            $file['name'],
            $mime
        );

        $id = $this->repo->create([
            'company_id' => $companyId,
            'document_type' => $documentType,
            'title' => $title,
            'file_path' => $key,
            'original_filename' => $file['name'],
            'mime_type' => $mime,
            'size_bytes' => (int) $file['size'],
            'uploaded_by_user_id' => $userId,
        ]);

        $row = $this->repo->findById($id);

        return $this->formatRow($row, true);
    }

    /**
     * Rename / recategorise a document without replacing the underlying file.
     */
    public function updateMeta(int $id, mixed $title, mixed $type): array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Dokument nebyl nalezen');
        }

        $this->repo->updateMeta($id, [
            'title' => $this->normaliseTitle($title),
            'document_type' => $this->normaliseType($type),
        ]);

        return $this->formatRow($this->repo->findById($id), true);
    }

    public function deleteById(int $id): void
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Dokument nebyl nalezen');
        }

        $this->repo->delete($id);

        // R2 deletion is best-effort — the DB row is already gone, so an orphaned object
        // in storage is the lesser evil than failing the user's delete.
        $stored = $existing['file_path'] ?? '';
        if ($stored !== '' && !str_starts_with($stored, '/uploads/')) {
            try {
                $key = $this->storage->extractKey($stored);
                if ($key !== '') {
                    $this->storage->delete($key);
                }
            } catch (\Throwable $e) {
                // swallow — see note above
            }
        }
    }

    /**
     * Fetch the raw row for an ownership check + streaming download. Returns null when
     * the document does not exist so the caller can map to 404.
     */
    public function findById(int $id): ?array
    {
        return $this->repo->findById($id);
    }

    /**
     * Pull file content + filename for a stored document, normalising legacy URL/path
     * shapes into a bare R2 key first.
     *
     * @return array{content: string, filename: string, mimeType: string}
     */
    public function getFileForDownload(array $document): array
    {
        $stored = (string) ($document['file_path'] ?? '');
        if ($stored === '') {
            throw new NotFoundException('Soubor dokumentu nebyl nalezen');
        }

        $key = $this->storage->extractKey($stored);
        $object = $this->storage->getObjectWithMeta($key);

        return [
            'content' => $object['body'],
            'filename' => (string) ($document['original_filename'] ?? basename($key)),
            'mimeType' => $object['contentType'],
        ];
    }

    private function validateFile(array $file): string
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Soubor se nepodařilo nahrát.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException('Soubor je příliš velký. Maximum je 10 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException('Nepodporovaný typ souboru. Povoleno: PDF, Word, Excel a obrázky (JPG, PNG).');
        }

        return $mime;
    }

    private function normaliseTitle(mixed $title): string
    {
        $title = is_string($title) ? trim($title) : '';
        if ($title === '') {
            throw new ValidationException('Zadejte název dokumentu.', ['title' => 'Název dokumentu je povinný.']);
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new ValidationException(
                'Název dokumentu je příliš dlouhý.',
                ['title' => 'Maximální délka je ' . self::MAX_TITLE_LENGTH . ' znaků.']
            );
        }
        return $title;
    }

    private function normaliseType(mixed $type): ?string
    {
        if (!is_string($type)) {
            return null;
        }
        $type = trim($type);
        if ($type === '') {
            return null;
        }
        if (mb_strlen($type) > self::MAX_TYPE_LENGTH) {
            throw new ValidationException(
                'Typ dokumentu je příliš dlouhý.',
                ['documentType' => 'Maximální délka je ' . self::MAX_TYPE_LENGTH . ' znaků.']
            );
        }
        return $type;
    }

    private function formatRow(array $row, bool $withUrl): array
    {
        $formatted = [
            'id' => (int) $row['id'],
            'companyId' => (int) $row['company_id'],
            'documentType' => $row['document_type'] !== null && $row['document_type'] !== ''
                ? (string) $row['document_type']
                : null,
            'title' => (string) $row['title'],
            'filename' => (string) $row['original_filename'],
            'mimeType' => (string) $row['mime_type'],
            'sizeBytes' => (int) $row['size_bytes'],
            'uploadedAt' => $row['created_at'] ?? null,
        ];

        if ($withUrl) {
            // Stable HMAC-signed proxy URL — only handed to the trusted admin UI for
            // inline preview. Client downloads stream through an authenticated endpoint.
            $formatted['url'] = $this->storage->resolveProxyUrl($row['file_path'] ?? null);
        }

        return $formatted;
    }
}
