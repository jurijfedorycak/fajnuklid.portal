<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CompanyRepository;
use App\Repositories\MaintenanceRequestRepository;

class MaintenanceRequestService
{
    public const STATUSES = ['prijato', 'resi_se', 'ceka_na_potvrzeni', 'vyreseno', 'zablokovano'];
    public const CATEGORIES = ['elektro', 'voda', 'klima', 'uklid', 'pristupy', 'jine'];
    public const LOCATION_TYPES = ['office', 'common', 'custom'];

    private MaintenanceRequestRepository $repo;
    private CompanyRepository $companyRepo;

    public function __construct(
        ?MaintenanceRequestRepository $repo = null,
        ?CompanyRepository $companyRepo = null
    ) {
        $this->repo = $repo ?? new MaintenanceRequestRepository();
        $this->companyRepo = $companyRepo ?? new CompanyRepository();
    }

    /**
     * Resolve the client_id for a given user. Returns null if user has no company.
     */
    public function resolveClientIdForUser(int $userId): ?int
    {
        $companies = $this->companyRepo->findByUserId($userId);
        if (empty($companies)) {
            return null;
        }

        return (int) $companies[0]['client_id'];
    }

    public function listForClient(int $clientId, ?string $status = null): array
    {
        $rows = $this->repo->findByClientId($clientId, $status);
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getForClient(int $id, int $clientId): array
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Žádost nebyla nalezena');
        }

        $request = $this->formatRow($row);
        $request['activity'] = $this->formatActivity($this->repo->findActivity($id, false));

        return $request;
    }

    public function create(int $clientId, int $userId, array $input): array
    {
        $errors = $this->validateCreatePayload($input);
        if (!empty($errors)) {
            throw new ValidationException('Validace selhala', $errors);
        }

        // company_id, if provided, must belong to the same client
        $companyId = null;
        if (!empty($input['companyId'])) {
            $company = $this->companyRepo->findById((int) $input['companyId']);
            if ($company === null || (int) $company['client_id'] !== $clientId) {
                throw new ValidationException('Validace selhala', [
                    'companyId' => ['Vybraná pobočka nepatří k vašemu účtu.'],
                ]);
            }
            $companyId = (int) $company['id'];
        }

        $newId = $this->repo->create([
            'client_id' => $clientId,
            'company_id' => $companyId,
            'created_by_user_id' => $userId,
            'title' => trim((string) $input['title']),
            'category' => $input['category'],
            'location_type' => $input['locationType'],
            'location_value' => isset($input['locationValue']) ? trim((string) $input['locationValue']) : null,
            'description' => isset($input['description']) ? trim((string) $input['description']) : null,
            'status' => 'prijato',
        ]);

        $this->repo->addActivity([
            'request_id' => $newId,
            'user_id' => $userId,
            'author_type' => 'system',
            'author_name' => 'Systém',
            'message' => 'Žádost byla vytvořena.',
            'status_change' => 'prijato',
        ]);

        return $this->getForClient($newId, $clientId);
    }

    /**
     * Client confirms that a request is resolved.
     * Only allowed when status = ceka_na_potvrzeni.
     */
    public function clientConfirm(int $id, int $clientId, int $userId, string $userName): array
    {
        $row = $this->repo->findByIdForClient($id, $clientId);
        if ($row === null) {
            throw new NotFoundException('Žádost nebyla nalezena');
        }
        if ($row['status'] !== 'ceka_na_potvrzeni') {
            throw new ValidationException('Žádost nelze potvrdit v tomto stavu', [
                'status' => ['Potvrdit lze pouze žádost ve stavu „Čeká na vaše potvrzení".'],
            ]);
        }

        $this->repo->updateStatus($id, 'vyreseno');
        $this->repo->addActivity([
            'request_id' => $id,
            'user_id' => $userId,
            'author_type' => 'client',
            'author_name' => $userName,
            'message' => 'Klient potvrdil vyřešení žádosti.',
            'status_change' => 'vyreseno',
        ]);

        return $this->getForClient($id, $clientId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function listForAdmin(?int $clientId = null, ?string $status = null): array
    {
        $rows = $this->repo->findAllForAdmin($clientId, $status);
        return array_map([$this, 'formatRow'], $rows);
    }

    public function getForAdmin(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new NotFoundException('Žádost nebyla nalezena');
        }
        $request = $this->formatRow($row);
        $request['activity'] = $this->formatActivity($this->repo->findActivity($id, true));
        return $request;
    }

    public function adminUpdate(int $id, int $adminUserId, string $adminName, array $input): array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Žádost nebyla nalezena');
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
            throw new NotFoundException('Žádost nebyla nalezena');
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
            throw new NotFoundException('Žádost nebyla nalezena');
        }
        $this->repo->softDelete($id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function validateCreatePayload(array $input): array
    {
        $errors = [];

        $title = isset($input['title']) ? trim((string) $input['title']) : '';
        if ($title === '') {
            $errors['title'] = ['Název problému je povinný.'];
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = ['Název problému nesmí být delší než 255 znaků.'];
        }

        if (empty($input['category']) || !in_array($input['category'], self::CATEGORIES, true)) {
            $errors['category'] = ['Vyberte platnou kategorii.'];
        }

        if (empty($input['locationType']) || !in_array($input['locationType'], self::LOCATION_TYPES, true)) {
            $errors['locationType'] = ['Vyberte platný typ místa.'];
        }

        $locationValue = isset($input['locationValue']) ? trim((string) $input['locationValue']) : '';
        if ($locationValue === '') {
            $errors['locationValue'] = ['Místo je povinné.'];
        } elseif (mb_strlen($locationValue) > 255) {
            $errors['locationValue'] = ['Místo nesmí být delší než 255 znaků.'];
        }

        if (isset($input['description']) && mb_strlen((string) $input['description']) > 5000) {
            $errors['description'] = ['Popis nesmí být delší než 5000 znaků.'];
        }

        return $errors;
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'clientId' => (int) $row['client_id'],
            'companyId' => isset($row['company_id']) ? (int) $row['company_id'] : null,
            'title' => $row['title'],
            'category' => $row['category'],
            'locationType' => $row['location_type'] ?? null,
            'locationValue' => $row['location_value'] ?? null,
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
}
