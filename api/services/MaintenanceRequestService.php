<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CompanyRepository;
use App\Repositories\MaintenanceRequestRepository;

class MaintenanceRequestService
{
    public const STATUSES = ['prijato', 'resi_se', 'vyreseno', 'zablokovano'];
    public const OPEN_STATUSES = ['prijato', 'resi_se'];
    public const CATEGORIES = ['reklamace', 'mimoradna_prace', 'jine'];
    public const LOCATION_TYPES = ['office', 'common', 'custom'];

    private const NOTIFICATION_RECIPIENT = 'jurij.fedorycak@fajnuklid.cz';

    public const ATTACHMENT_MAX_PER_REQUEST = 5;
    public const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
    public const ATTACHMENT_ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];

    private MaintenanceRequestRepository $repo;
    private CompanyRepository $companyRepo;
    private ?MailerService $mailer;
    private R2StorageService $storage;

    public function __construct(
        ?MaintenanceRequestRepository $repo = null,
        ?CompanyRepository $companyRepo = null,
        ?MailerService $mailer = null,
        ?R2StorageService $storage = null
    ) {
        $this->repo = $repo ?? new MaintenanceRequestRepository();
        $this->companyRepo = $companyRepo ?? new CompanyRepository();
        $this->mailer = $mailer;
        $this->storage = $storage ?? new R2StorageService();
    }

    public function resolveClientIdForUser(int $userId): ?int
    {
        $companies = $this->companyRepo->findByUserId($userId);
        if (empty($companies)) {
            return null;
        }
        return (int) $companies[0]['client_id'];
    }

    public function listForClient(int $clientId, ?string $status = null, ?int $limit = null): array
    {
        $statuses = null;
        if ($status === 'open') {
            $statuses = self::OPEN_STATUSES;
        } elseif ($status !== null && $status !== '') {
            $statuses = [$status];
        }
        $rows = $this->repo->findByClientId($clientId, $statuses, $limit);
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getForClient(int $id, int $clientId): array
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Žádost nebyla nalezena');
        }
        return $this->buildRequestPayload($row, false);
    }

    public function create(int $clientId, int $userId, array $input, bool $sendNotifications = true): array
    {
        $errors = $this->validateCreatePayload($input);
        if (!empty($errors)) {
            throw new ValidationException('Validace selhala', $errors);
        }

        // companyId required and must belong to this client
        $companyId = (int) $input['companyId'];
        $company = $this->companyRepo->findById($companyId);
        if ($company === null || (int) $company['client_id'] !== $clientId) {
            throw new ValidationException('Validace selhala', [
                'companyId' => ['Vybraná protistrana nepatří k vašemu účtu.'],
            ]);
        }

        $newId = $this->repo->create([
            'client_id' => $clientId,
            'company_id' => $companyId,
            'created_by_user_id' => $userId,
            'title' => trim((string) $input['title']),
            'category' => !empty($input['category']) ? $input['category'] : null,
            'location_type' => null,
            'location_value' => null,
            'description' => isset($input['description']) ? trim((string) $input['description']) : null,
            'status' => 'prijato',
        ]);

        $this->repo->addActivity([
            'request_id' => $newId,
            'user_id' => $userId,
            'author_type' => 'system',
            'author_name' => 'Systém',
            'message' => 'Požadavek byl vytvořen.',
            'status_change' => 'prijato',
        ]);

        $payload = $this->getForClient($newId, $clientId);

        if ($sendNotifications) {
            $this->sendCreateNotifications($payload, $userId, $company);
        }

        return $payload;
    }

    public function clientConfirm(int $id, int $clientId, int $userId, string $userName): array
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        if ($row['status'] !== 'resi_se') {
            throw new ValidationException('Požadavek nelze potvrdit v tomto stavu', [
                'status' => ['Potvrdit lze pouze požadavek ve stavu „V řešení".'],
            ]);
        }

        $this->repo->updateStatus($id, 'vyreseno');
        $this->repo->addActivity([
            'request_id' => $id,
            'user_id' => $userId,
            'author_type' => 'client',
            'author_name' => $userName,
            'message' => 'Klient potvrdil vyřešení požadavku.',
            'status_change' => 'vyreseno',
        ]);

        return $this->getForClient($id, $clientId);
    }

    public function clientReject(int $id, int $clientId, int $userId, string $userName, string $comment): array
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        if ($row['status'] !== 'resi_se') {
            throw new ValidationException('Požadavek nelze odmítnout v tomto stavu', [
                'status' => ['Odmítnout lze pouze požadavek ve stavu „V řešení".'],
            ]);
        }
        $comment = trim($comment);
        if (mb_strlen($comment) < 3) {
            throw new ValidationException('Validace selhala', [
                'comment' => ['Uveďte prosím důvod (alespoň 3 znaky).'],
            ]);
        }

        $this->repo->addActivity([
            'request_id' => $id,
            'user_id' => $userId,
            'author_type' => 'client',
            'author_name' => $userName,
            'message' => $comment,
            'status_change' => null,
        ]);

        return $this->getForClient($id, $clientId);
    }

    public function clientCancel(int $id, int $clientId, int $userId, string $userName): void
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        if ($row['status'] !== 'prijato') {
            throw new ValidationException('Požadavek nelze zrušit v tomto stavu', [
                'status' => ['Zrušit lze pouze požadavek ve stavu „Nový".'],
            ]);
        }

        $this->repo->addActivity([
            'request_id' => $id,
            'user_id' => $userId,
            'author_type' => 'client',
            'author_name' => $userName,
            'message' => 'Klient zrušil požadavek.',
            'status_change' => 'cancelled',
        ]);
        $this->repo->softDelete($id);
    }

    public function calendarForClient(int $clientId, int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            throw new ValidationException('Validace selhala', [
                'month' => ['Měsíc musí být v rozmezí 1–12.'],
            ]);
        }
        return $this->repo->countByDayForClient($clientId, $year, $month);
    }

    // ─────────── Attachments ───────────

    public function addClientAttachment(int $requestId, int $clientId, int $userId, array $file): array
    {
        $row = $this->repo->findByIdForClient($requestId, $clientId);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        return $this->storeAttachment($requestId, $userId, $file, 'before');
    }

    public function addAdminAttachment(int $requestId, int $userId, array $file, string $phase = 'after'): array
    {
        $row = $this->repo->findById($requestId);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        return $this->storeAttachment($requestId, $userId, $file, $phase);
    }

    private function storeAttachment(int $requestId, int $userId, array $file, string $phase): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Soubor se nepodařilo nahrát.');
        }
        if ((int) $file['size'] > self::ATTACHMENT_MAX_BYTES) {
            throw new ValidationException('Soubor je příliš velký. Maximum je 10 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ATTACHMENT_ALLOWED_MIMES, true)) {
            throw new ValidationException('Nepodporovaný typ souboru. Povoleno: obrázky a PDF.');
        }

        $existingCount = $this->repo->countAttachments($requestId, $phase);
        if ($existingCount >= self::ATTACHMENT_MAX_PER_REQUEST) {
            throw new ValidationException('Maximální počet příloh (5) byl dosažen.');
        }

        $key = $this->storage->upload(
            'maintenance-request-attachments',
            $file['tmp_name'],
            $file['name'],
            $mime
        );

        $id = $this->repo->addAttachment([
            'request_id' => $requestId,
            'phase' => $phase,
            'file_path' => $key,
            'original_filename' => $file['name'],
            'mime_type' => $mime,
            'size_bytes' => (int) $file['size'],
            'uploaded_by_user_id' => $userId,
        ]);

        return [
            'id' => $id,
            'phase' => $phase,
            'url' => $this->attachmentUrl($key),
            'filename' => $file['name'],
            'mimeType' => $mime,
            'sizeBytes' => (int) $file['size'],
        ];
    }

    private function attachmentUrl(string $keyOrPath): ?string
    {
        // Use the stable HMAC-signed proxy URL so attachments don't expire while
        // a maintenance-request view is open in the browser. Return null (not '')
        // so FE doesn't accidentally render <img src=""> and re-request the page.
        return $this->storage->resolveProxyUrl($keyOrPath);
    }

    // ─────────── Admin ───────────

    public function listForAdmin(?int $clientId = null, ?string $status = null): array
    {
        $rows = $this->repo->findAllForAdmin($clientId, $status);
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getForAdmin(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        return $this->buildRequestPayload($row, true);
    }

    public function adminUpdate(int $id, int $adminUserId, string $adminName, array $input): array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }

        $update = [];
        $errors = [];

        if (array_key_exists('status', $input)) {
            if (!in_array($input['status'], self::STATUSES, true)) {
                $errors['status'] = ['Neplatný stav.'];
            } else {
                $update['status'] = $input['status'];
            }
        }
        if (array_key_exists('dueDate', $input)) {
            $dueDate = $input['dueDate'];
            if ($dueDate === '' || $dueDate === null) {
                $update['due_date'] = null;
            } else {
                $parsed = \DateTime::createFromFormat('Y-m-d', (string) $dueDate);
                if ($parsed === false || $parsed->format('Y-m-d') !== $dueDate) {
                    $errors['dueDate'] = ['Termín musí být ve formátu RRRR-MM-DD.'];
                } else {
                    $update['due_date'] = $dueDate;
                }
            }
        }
        if (array_key_exists('title', $input) && trim((string) $input['title']) !== '') {
            $update['title'] = trim((string) $input['title']);
        }

        if (!empty($errors)) {
            throw new ValidationException('Validace selhala', $errors);
        }

        if (!empty($update)) {
            $this->repo->update($id, $update);
        }

        if (isset($update['status']) && $update['status'] !== $existing['status']) {
            $this->repo->addActivity([
                'request_id' => $id,
                'user_id' => $adminUserId,
                'author_type' => 'admin',
                'author_name' => $adminName,
                'message' => 'Stav byl změněn.',
                'status_change' => $update['status'],
            ]);
        }

        return $this->getForAdmin($id);
    }

    public function adminAddActivity(int $id, int $adminUserId, string $adminName, string $message, bool $isInternal = false): array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }

        $message = trim($message);
        if ($message === '') {
            throw new ValidationException('Validace selhala', [
                'message' => ['Zpráva je povinná.'],
            ]);
        }

        $this->repo->addActivity([
            'request_id' => $id,
            'user_id' => $adminUserId,
            'author_type' => 'admin',
            'author_name' => $adminName,
            'message' => $message,
            'is_internal' => $isInternal,
        ]);

        return $this->getForAdmin($id);
    }

    public function adminDelete(int $id): void
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Požadavek nebyl nalezen');
        }
        $this->repo->softDelete($id);
    }

    // ─────────── Helpers ───────────

    private function buildRequestPayload(array $row, bool $includeInternal): array
    {
        $request = $this->formatRow($row);
        $request['activity'] = $this->formatActivity($this->repo->findActivity((int) $row['id'], $includeInternal));
        $attachments = $this->repo->findAttachments((int) $row['id']);
        $request['attachments'] = [
            'before' => [],
            'after' => [],
        ];
        foreach ($attachments as $a) {
            $entry = [
                'id' => (int) $a['id'],
                'url' => $this->attachmentUrl($a['file_path']),
                'filename' => $a['original_filename'],
                'mimeType' => $a['mime_type'],
                'sizeBytes' => (int) $a['size_bytes'],
                'createdAt' => $a['created_at'],
            ];
            $request['attachments'][$a['phase']][] = $entry;
        }
        return $request;
    }

    private function validateCreatePayload(array $input): array
    {
        $errors = [];

        $title = isset($input['title']) ? trim((string) $input['title']) : '';
        if ($title === '') {
            $errors['title'] = ['Název je povinný.'];
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = ['Název nesmí být delší než 255 znaků.'];
        }

        $description = isset($input['description']) ? trim((string) $input['description']) : '';
        if ($description === '') {
            $errors['description'] = ['Podrobný popis je povinný.'];
        } elseif (mb_strlen($description) > 5000) {
            $errors['description'] = ['Popis nesmí být delší než 5000 znaků.'];
        }

        if (!empty($input['category']) && !in_array($input['category'], self::CATEGORIES, true)) {
            $errors['category'] = ['Vyberte platnou kategorii.'];
        }

        if (empty($input['companyId']) || !is_numeric($input['companyId'])) {
            $errors['companyId'] = ['Vyberte protistranu.'];
        }

        return $errors;
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clientId' => (int) $row['client_id'],
            'companyId' => isset($row['company_id']) ? (int) $row['company_id'] : null,
            'companyName' => $row['company_name'] ?? null,
            'companyIco' => $row['company_ico'] ?? null,
            'title' => $row['title'],
            'category' => $row['category'],
            'description' => $row['description'] ?? null,
            'status' => $row['status'],
            'dueDate' => $row['due_date'] ?? null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'] ?? null,
            'createdBy' => $row['created_by_email'] ?? null,
            'clientDisplayName' => $row['client_display_name'] ?? null,
        ];
    }

    private function formatActivity(array $rows): array
    {
        return array_map(function ($r) {
            return [
                'id' => (int) $r['id'],
                'authorType' => $r['author_type'],
                'author' => $r['author_name'] ?? ($r['author_type'] === 'admin' ? 'Fajn Úklid' : 'Klient'),
                'message' => $r['message'],
                'statusChange' => $r['status_change'],
                'isInternal' => !empty($r['is_internal']),
                'createdAt' => $r['created_at'],
            ];
        }, $rows);
    }

    private function sendCreateNotifications(array $payload, int $userId, array $company): void
    {
        try {
            $mailer = $this->mailer ?? new MailerService();
        } catch (\Throwable $e) {
            error_log('MailerService init failed: ' . $e->getMessage());
            return;
        }

        $categoryLabels = [
            'reklamace' => 'Reklamace',
            'mimoradna_prace' => 'Mimořádná práce',
            'jine' => 'Jiné',
        ];
        $authorEmail = $payload['createdBy'] ?? null;
        if ($authorEmail !== null && strtolower(trim($authorEmail)) === self::NOTIFICATION_RECIPIENT) {
            return;
        }

        $categoryLabel = $payload['category'] ? ($categoryLabels[$payload['category']] ?? $payload['category']) : '—';
        $createdAt = (new \DateTime($payload['createdAt']))->format('d.m.Y H:i');

        $title = htmlspecialchars($payload['title'], ENT_QUOTES, 'UTF-8');
        $description = nl2br(htmlspecialchars($payload['description'] ?? '', ENT_QUOTES, 'UTF-8'));
        $companyName = htmlspecialchars((string) ($company['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyIco = htmlspecialchars((string) ($company['registration_number'] ?? ''), ENT_QUOTES, 'UTF-8');

        $internalHtml = "<p>Nový požadavek od klienta.</p>"
            . "<h3>{$title}</h3>"
            . "<p><strong>Kategorie:</strong> {$categoryLabel}<br>"
            . "<strong>Vytvořeno:</strong> {$createdAt}<br>"
            . "<strong>Protistrana:</strong> {$companyName} ({$companyIco})<br>"
            . "<strong>Autor:</strong> " . htmlspecialchars((string) $authorEmail, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p>{$description}</p>";

        try {
            $subject = 'Nový požadavek: ' . $payload['title'];
            $mailer->send(self::NOTIFICATION_RECIPIENT, $subject, $internalHtml);
        } catch (\Throwable $e) {
            error_log('Maintenance request notification failed: ' . $e->getMessage());
        }
    }
}
