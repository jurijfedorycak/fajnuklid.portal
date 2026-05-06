<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyUserRepository;
use App\Repositories\CompanyContactRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\EmployeeLocationRepository;
use App\Repositories\ClientEmployeeRepository;
use App\Repositories\UserRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ClientContactRepository;
use App\Repositories\StaffContactRepository;
use App\Services\IDokladService;
use App\Services\MaintenanceRequestService;
use App\Services\R2StorageService;
use App\Services\StaffLoginService;
use App\Helpers\PasswordHelper;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class AdminController extends Controller
{
    private ClientRepository $clientRepo;
    private CompanyRepository $companyRepo;
    private CompanyUserRepository $companyUserRepo;
    private CompanyContactRepository $companyContactRepo;
    private EmployeeRepository $employeeRepo;
    private EmployeeLocationRepository $employeeLocationRepo;
    private ClientEmployeeRepository $clientEmployeeRepo;
    private UserRepository $userRepo;
    private LocationRepository $locationRepo;
    private ClientContactRepository $clientContactRepo;
    private StaffContactRepository $staffContactRepo;
    private MaintenanceRequestService $maintenanceRequestService;
    private IDokladService $idokladService;
    private R2StorageService $storage;
    private StaffLoginService $staffLoginService;

    public function __construct()
    {
        $this->clientRepo = new ClientRepository();
        $this->companyRepo = new CompanyRepository();
        $this->companyUserRepo = new CompanyUserRepository();
        $this->companyContactRepo = new CompanyContactRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
        $this->clientEmployeeRepo = new ClientEmployeeRepository();
        $this->userRepo = new UserRepository();
        $this->locationRepo = new LocationRepository();
        $this->clientContactRepo = new ClientContactRepository();
        $this->staffContactRepo = new StaffContactRepository();
        $this->maintenanceRequestService = new MaintenanceRequestService();
        $this->idokladService = new IDokladService();
        $this->storage = new R2StorageService();
        $this->staffLoginService = new StaffLoginService($this->userRepo, $this->staffContactRepo);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // iDoklad manual sync (admin-triggered, read-only fetch from iDoklad)
    // ─────────────────────────────────────────────────────────────────────────────

    public function syncIdokladForCompany(Request $request): void
    {
        $companyId = (int) $request->param('id');
        $company = $this->companyRepo->findById($companyId);

        if (!$company) {
            throw new NotFoundException('Firma nebyla nalezena');
        }

        try {
            $result = $this->idokladService->syncInvoicesForCompany($companyId);
        } catch (\Throwable $e) {
            Response::json([
                'success' => false,
                'message' => 'Výjimka při synchronizaci',
                'data' => [
                    'synced' => 0,
                    'error_details' => [
                        'context' => 'service exception',
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile() . ':' . $e->getLine(),
                        'trace' => $this->trimmedTrace($e),
                    ],
                ],
            ], 200);
            return;
        }

        Response::json([
            'success' => (bool) $result['success'],
            'message' => $result['message'] ?? '',
            'data' => $result,
        ], 200);
    }

    private function trimmedTrace(\Throwable $e): array
    {
        $frames = array_slice($e->getTrace(), 0, 10);
        return array_map(static function (array $frame): string {
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $loc = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
            return $fn . ' at ' . $loc;
        }, $frames);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CLIENTS
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Collect all validation errors for the client edit/create payload upfront, so the
     * frontend can show each problem on the exact row it belongs to instead of failing
     * halfway through a DB transaction with a generic message.
     *
     * Error keys use dot-paths that mirror the FE form shape:
     *   display_name                 — top-level
     *   client_id                    — top-level (create only)
     *   logins.<i>.email
     *   icos.<i>.ico
     *   icos.<i>.official_name
     *   icos.<i>.objects.<j>.name
     *   contacts.<i>.email
     *   contacts.<i>.ico_id
     *
     * @return array<string, array<int, string>>
     */
    /**
     * Turn a MySQL unique-constraint violation into a user-facing ValidationException on the
     * given error path. Any other PDOException is returned as-is so the caller can rethrow.
     *
     * Needed because the pre-insert uniqueness check in validateClientPayload has a TOCTOU
     * window — a concurrent admin can claim the same client_id/IČO/e-mail between validation
     * and the insert. Without this, the race surfaces as a bare 500.
     */
    private function reclassifyUniqueViolation(\PDOException $e, string $errorPath, string $message): \Throwable
    {
        if ($e->getCode() === '23000') {
            return new ValidationException('Zkontrolujte prosím vyplněné údaje', [
                $errorPath => [$message],
            ]);
        }
        return $e;
    }

    private function validateClientPayload(array $payload, ?int $updatingClientDbId): array
    {
        $errors = [];
        $isCreate = $updatingClientDbId === null;

        $displayName = isset($payload['display_name']) ? trim((string) $payload['display_name']) : '';
        if ($isCreate && $displayName === '') {
            $errors['display_name'][] = 'Název pro portál je povinný';
        }
        if ($displayName !== '' && mb_strlen($displayName) > 255) {
            $errors['display_name'][] = 'Název může mít nejvýše 255 znaků';
        }

        // client_id is immutable after creation — only validate on create path.
        if ($isCreate) {
            $clientId = isset($payload['client_id']) ? trim((string) $payload['client_id']) : '';
            if ($clientId === '') {
                $errors['client_id'][] = 'ID klienta je povinné';
            } elseif (mb_strlen($clientId) > 50) {
                $errors['client_id'][] = 'ID klienta může mít nejvýše 50 znaků';
            } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $clientId)) {
                $errors['client_id'][] = 'ID smí obsahovat pouze písmena, čísla, pomlčky a podtržítka';
            } elseif ($this->clientRepo->existsByClientId($clientId)) {
                $errors['client_id'][] = 'Toto ID už používá jiný klient — zvolte prosím jiné';
            }
        }

        // Pre-load existing companies for the current client so updateClient uniqueness
        // checks can exclude IČOs that already belong to this client (no false positive),
        // and can reject attempts to add new IČOs (update path doesn't persist new companies).
        $ownIcoSet = [];
        $ownUserIds = [];
        if (!$isCreate) {
            foreach ($this->companyRepo->findByClientId($updatingClientDbId) as $company) {
                $ownIcoSet[$company['registration_number']] = true;
                foreach ($this->companyUserRepo->findByCompanyId((int) $company['id']) as $link) {
                    $ownUserIds[(int) $link['user_id']] = true;
                }
            }
        }

        // IČOs — per-row validation with in-form duplicate detection
        $icos = is_array($payload['icos'] ?? null) ? $payload['icos'] : [];
        $seenIcoRow = [];
        foreach ($icos as $i => $icoData) {
            $path = "icos.{$i}";
            if (!is_array($icoData)) {
                continue;
            }
            $ico = isset($icoData['ico']) ? trim((string) $icoData['ico']) : '';
            $officialName = isset($icoData['official_name']) ? trim((string) $icoData['official_name']) : '';

            $hasAnyContent = $ico !== '' || $officialName !== ''
                || !empty($icoData['objects']) || !empty($icoData['contract_file']);

            if ($ico === '') {
                if ($hasAnyContent) {
                    $errors["{$path}.ico"][] = 'IČO je povinné';
                }
            } elseif (!preg_match('/^\d{8}$/', $ico)) {
                $errors["{$path}.ico"][] = 'IČO musí mít přesně 8 číslic';
            } else {
                $isOwnIco = !$isCreate && isset($ownIcoSet[$ico]);

                if (isset($seenIcoRow[$ico])) {
                    $errors["{$path}.ico"][] = 'Toto IČO už máte ve formuláři (řádek ' . ($seenIcoRow[$ico] + 1) . ')';
                } elseif (!$isCreate && !$isOwnIco) {
                    // Update path only touches existing companies — new rows would be
                    // silently dropped, so reject upfront with a clear message.
                    $errors["{$path}.ico"][] = 'Přidávání nových IČO při úpravě zatím není podporováno — kontaktujte vývojáře';
                } elseif ($isCreate && $this->companyRepo->existsByRegistrationNumber($ico)) {
                    $errors["{$path}.ico"][] = "IČO {$ico} už patří jinému klientovi — zkontrolujte, zda jste ho nepřeklepli";
                }
                $seenIcoRow[$ico] = $i;
            }

            $objects = is_array($icoData['objects'] ?? null) ? $icoData['objects'] : [];
            foreach ($objects as $j => $obj) {
                if (!is_array($obj)) {
                    continue;
                }
                $objName = isset($obj['name']) ? trim((string) $obj['name']) : '';
                $objAddress = isset($obj['address']) ? trim((string) $obj['address']) : '';
                $hasAnyObjContent = $objName !== '' || $objAddress !== ''
                    || ($obj['lat'] ?? null) !== null || ($obj['lng'] ?? null) !== null;
                if ($objName === '' && $hasAnyObjContent) {
                    $errors["{$path}.objects.{$j}.name"][] = 'Název provozovny je povinný';
                }
            }
        }

        $logins = is_array($payload['logins'] ?? null) ? $payload['logins'] : [];
        $seenEmailRow = [];
        foreach ($logins as $i => $loginData) {
            $path = "logins.{$i}";
            if (!is_array($loginData)) {
                continue;
            }
            $email = isset($loginData['email']) ? trim((string) $loginData['email']) : '';
            $restriction = $loginData['restriction'] ?? 'all';
            $allowedIcos = is_array($loginData['allowed_icos'] ?? null) ? $loginData['allowed_icos'] : [];
            $userId = isset($loginData['user_id']) && is_numeric($loginData['user_id'])
                ? (int) $loginData['user_id']
                : null;

            // user_id echoed from getClient identifies an existing account — it must belong
            // to this client, otherwise someone is trying to hijack another client's login.
            if ($userId !== null && ($isCreate || !isset($ownUserIds[$userId]))) {
                $errors["{$path}.email"][] = 'Neznámý přihlašovací účet — obnovte stránku';
                continue;
            }

            // Empty rows are valid on create (treated as "didn't finish") — but flagged once
            // the user typed anything, otherwise silently skipped at save time.
            $hasAnyContent = $email !== '' || !empty($loginData['temp_pass'])
                || $userId !== null
                || ($restriction === 'icos' && !empty($allowedIcos));

            if ($email === '') {
                if ($hasAnyContent) {
                    $errors["{$path}.email"][] = 'E-mail je povinný';
                }
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors["{$path}.email"][] = 'E-mail není ve správném formátu (např. jmeno@firma.cz)';
            } else {
                $emailLower = mb_strtolower($email);
                if (isset($seenEmailRow[$emailLower])) {
                    $errors["{$path}.email"][] = 'Tento e-mail už máte ve formuláři (řádek ' . ($seenEmailRow[$emailLower] + 1) . ')';
                } elseif ($this->userRepo->existsByEmail($email, $userId)) {
                    $errors["{$path}.email"][] = 'Tento e-mail už v systému existuje — pro nový přístup zvolte jiný';
                }
                $seenEmailRow[$emailLower] = $i;
            }

            if ($restriction === 'icos' && empty($allowedIcos)) {
                $errors["{$path}.allowed_icos"][] = 'Vyberte alespoň jedno IČO, ke kterému bude účet mít přístup';
            }
        }

        $contacts = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
        foreach ($contacts as $i => $contactData) {
            $path = "contacts.{$i}";
            if (!is_array($contactData)) {
                continue;
            }
            $name = isset($contactData['name']) ? trim((string) $contactData['name']) : '';
            $email = isset($contactData['email']) ? trim((string) $contactData['email']) : '';
            $phone = isset($contactData['phone']) ? trim((string) $contactData['phone']) : '';
            $role = isset($contactData['role']) ? trim((string) $contactData['role']) : '';
            $scope = $contactData['scope'] ?? 'global';
            $icoId = $contactData['ico_id'] ?? null;

            $hasAnyContent = $name !== '' || $email !== '' || $phone !== '' || $role !== '';
            if ($hasAnyContent && $name === '') {
                $errors["{$path}.name"][] = 'Jméno kontaktní osoby je povinné';
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors["{$path}.email"][] = 'E-mail není ve správném formátu';
            }
            if ($name !== '' && $scope === 'icos' && ($icoId === null || $icoId === '')) {
                $errors["{$path}.ico_id"][] = 'Vyberte IČO, ke kterému kontakt patří';
            }
        }

        return $errors;
    }

    public function listClients(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $clients = $this->clientRepo->findPaginated(
            $pagination['per_page'],
            $pagination['offset'],
            $search
        );
        $total = $this->clientRepo->countAll($search);

        // Enrich each client with additional info
        $enrichedClients = [];
        foreach ($clients as $client) {
            $companies = $this->companyRepo->findByClientId((int) $client['id']);
            $icos = array_column($companies, 'registration_number');

            // Get login accounts for this client's companies
            $logins = [];
            $lastLogin = null;
            foreach ($companies as $company) {
                $users = $this->companyUserRepo->findByCompanyId((int) $company['id']);
                foreach ($users as $userLink) {
                    $user = $this->userRepo->findById((int) $userLink['user_id']);
                    if ($user) {
                        $logins[] = $user;
                        if ($lastLogin === null || $user['updated_at'] > $lastLogin) {
                            $lastLogin = $user['updated_at'];
                        }
                    }
                }
            }

            // Check if any login is active
            $active = false;
            foreach ($logins as $login) {
                if ($login['portal_enabled']) {
                    $active = true;
                    break;
                }
            }

            $enrichedClients[] = [
                'id' => $client['id'],
                'client_id' => $client['client_id'],
                'display_name' => $client['display_name'],
                'icos' => $icos,
                'active' => $active,
                'last_login' => $lastLogin,
                'email' => !empty($logins) ? $logins[0]['email'] : null,
                'created_at' => $client['created_at'],
            ];
        }

        Response::paginated($enrichedClients, $total, $pagination['page'], $pagination['per_page']);
    }

    public function getClient(Request $request): void
    {
        $clientId = $request->param('id');
        $client = $this->clientRepo->findByClientId($clientId);

        if (!$client) {
            throw new NotFoundException('Klient nebyl nalezen');
        }

        $id = (int) $client['id'];

        // Get companies (IČOs)
        $companies = $this->companyRepo->findByClientId($id);
        $clientCompanyIds = array_map('intval', array_column($companies, 'id'));
        $companyIdToIco = [];
        foreach ($companies as $c) {
            $companyIdToIco[(int) $c['id']] = $c['registration_number'];
        }

        // Group logins by user id so a single account isn't returned once per linked company,
        // and so we can derive the real restriction scope (all vs specific IČOs).
        $loginsByUserId = [];
        $hasActiveLogin = false;
        foreach ($companies as $company) {
            $users = $this->companyUserRepo->findByCompanyId((int) $company['id']);
            foreach ($users as $userLink) {
                $userId = (int) $userLink['user_id'];
                if (!isset($loginsByUserId[$userId])) {
                    $user = $this->userRepo->findById($userId);
                    if (!$user) {
                        continue;
                    }
                    if ($user['portal_enabled']) {
                        $hasActiveLogin = true;
                    }
                    $loginsByUserId[$userId] = [
                        'userId' => $userId,
                        'email' => (string) $user['email'],
                        'portalEnabled' => (bool) $user['portal_enabled'],
                        'companyIds' => [],
                    ];
                }
                $loginsByUserId[$userId]['companyIds'][] = (int) $company['id'];
            }
        }

        $logins = [];
        foreach ($loginsByUserId as $info) {
            $linkedCompanyIds = array_values(array_unique($info['companyIds']));
            $isAllIcos = count($linkedCompanyIds) === count($clientCompanyIds)
                && empty(array_diff($linkedCompanyIds, $clientCompanyIds));
            $allowedIcos = $isAllIcos ? [] : array_values(array_filter(array_map(
                fn($cid) => $companyIdToIco[$cid] ?? null,
                $linkedCompanyIds
            )));

            $logins[] = [
                'userId' => $info['userId'],
                'email' => $info['email'],
                'portalEnabled' => $info['portalEnabled'],
                'restriction' => $isAllIcos ? 'all' : 'icos',
                'allowedIcos' => $allowedIcos,
            ];
        }

        // Format IČOs for frontend (camelCase)
        $icos = array_map(function ($company) {
            $locations = $this->locationRepo->findByCompanyId((int) $company['id']);
            $objects = array_map(function ($loc) {
                return [
                    'id' => (int) $loc['id'],
                    'name' => $loc['name'] ?? '',
                    'address' => $loc['address'] ?? '',
                    'lat' => $loc['latitude'] !== null ? (float) $loc['latitude'] : null,
                    'lng' => $loc['longitude'] !== null ? (float) $loc['longitude'] : null,
                ];
            }, $locations);

            $contractPath = $company['contract_pdf_path'] ?? null;
            return [
                'id' => (int) $company['id'],
                'ico' => $company['registration_number'] ?? '',
                'officialName' => $company['name'] ?? '',
                'freshqrEnabled' => false,
                'idokladSyncEnabled' => (bool) ($company['idoklad_sync_enabled'] ?? false),
                'billingModel' => 'hourly',
                'contractUploaded' => !empty($contractPath),
                'contractFile' => $this->storage->resolveProxyUrl($contractPath),
                'objects' => $objects,
            ];
        }, $companies);

        // Get other clients for reassignment dropdown
        $allClients = $this->clientRepo->findAll();
        $otherClients = [];
        foreach ($allClients as $c) {
            if ($c['client_id'] !== $client['client_id']) {
                $otherClients[] = [
                    'clientId' => $c['client_id'],
                    'displayName' => $c['display_name'],
                ];
            }
        }

        // Load staff (employees assigned to client via client_employees)
        $clientEmployees = $this->clientEmployeeRepo->findByClientId($id);
        $locationMappings = $this->employeeLocationRepo->getLocationIdsByClientEmployees($id);
        $staff = array_map(function ($emp) use ($locationMappings) {
            $employeeId = (int) $emp['employee_id'];
            return [
                'id' => $employeeId,
                'employeeId' => $employeeId,
                'name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
                'role' => $emp['position'] ?? '',
                'phone' => $emp['phone'] ?? '',
                'tenure' => $emp['tenure_text'] ?? '',
                'bio' => $emp['bio'] ?? '',
                'photoUrl' => $this->storage->resolveProxyUrl($emp['photo_url'] ?? null),
                'assignedObjects' => $locationMappings[$employeeId] ?? [],
            ];
        }, $clientEmployees);

        // Load contacts with scope determination
        $contactRows = $this->clientContactRepo->findByClientId($id);
        $contactGroups = [];
        foreach ($contactRows as $row) {
            $contactId = (int) $row['id'];
            if (!isset($contactGroups[$contactId])) {
                $contactGroups[$contactId] = [
                    'contact' => $row,
                    'companyIds' => [],
                ];
            }
            $contactGroups[$contactId]['companyIds'][] = (int) $row['company_id'];
        }

        $companyIds = array_map('intval', array_column($companies, 'id'));
        $contacts = [];
        foreach ($contactGroups as $contactId => $group) {
            $contactCompanyIds = array_values(array_unique($group['companyIds']));
            $isGlobal = count($contactCompanyIds) === count($companyIds) &&
                        empty(array_diff($contactCompanyIds, $companyIds));

            $contacts[] = [
                'id' => $contactId,
                'name' => $group['contact']['name'] ?? '',
                'role' => $group['contact']['position'] ?? '',
                'phone' => $group['contact']['phone'] ?? '',
                'email' => $group['contact']['email'] ?? '',
                'scope' => $isGlobal ? 'global' : 'icos',
                'icoIds' => $isGlobal ? [] : $contactCompanyIds,
            ];
        }

        Response::success([
            'clientId' => $client['client_id'],
            'displayName' => $client['display_name'],
            'notes' => '',
            'active' => $hasActiveLogin,
            'logins' => $logins,
            'icos' => $icos,
            'staff' => $staff,
            'contacts' => $contacts,
            'otherClients' => $otherClients,
        ]);
    }

    public function createClient(Request $request): void
    {
        $payload = $request->all();
        $errors = $this->validateClientPayload($payload, null);
        if (!empty($errors)) {
            throw new ValidationException('Zkontrolujte prosím vyplněné údaje', $errors);
        }

        $data = [
            'client_id' => trim((string) ($payload['client_id'] ?? '')),
            'display_name' => trim((string) ($payload['display_name'] ?? '')),
        ];

        $db = \App\Config\Database::getConnection();
        $db->beginTransaction();

        try {
            try {
                $clientDbId = $this->clientRepo->create($data);
            } catch (\PDOException $e) {
                // Racing admin may have claimed the same client_id between validation and insert.
                throw $this->reclassifyUniqueViolation($e, 'client_id', 'Toto ID už používá jiný klient — zvolte prosím jiné');
            }

            $icos = is_array($payload['icos'] ?? null) ? $payload['icos'] : [];

            $companyIds = [];
            $icoToCompanyId = [];

            foreach ($icos as $i => $icoData) {
                if (!is_array($icoData)) {
                    continue;
                }
                $ico = trim((string) ($icoData['ico'] ?? ''));
                $officialName = trim((string) ($icoData['official_name'] ?? ''));

                if ($ico === '') {
                    continue;
                }

                $incomingContract = $icoData['contract_file'] ?? null;
                try {
                    $companyId = $this->companyRepo->create([
                        'client_id' => $clientDbId,
                        'registration_number' => $ico,
                        'name' => $officialName ?: $data['display_name'],
                        // FE echoes the resolved URL; persist the bare R2 key so the URL
                        // can be regenerated fresh on every read.
                        'contract_pdf_path' => is_string($incomingContract)
                            ? ($this->storage->extractKey($incomingContract) ?: null)
                            : null,
                        'idoklad_sync_enabled' => (bool) ($icoData['idoklad_sync_enabled'] ?? false),
                    ]);
                } catch (\PDOException $e) {
                    throw $this->reclassifyUniqueViolation(
                        $e,
                        "icos.{$i}.ico",
                        "IČO {$ico} už patří jinému klientovi — zkontrolujte, zda jste ho nepřeklepli"
                    );
                }

                $companyIds[] = $companyId;
                $icoToCompanyId[$ico] = $companyId;

                $objects = is_array($icoData['objects'] ?? null) ? $icoData['objects'] : [];
                foreach ($objects as $obj) {
                    if (!is_array($obj)) {
                        continue;
                    }
                    $this->locationRepo->create([
                        'company_id' => $companyId,
                        'name' => trim((string) ($obj['name'] ?? '')),
                        'address' => $obj['address'] ?? null,
                        'latitude' => $obj['lat'] ?? null,
                        'longitude' => $obj['lng'] ?? null,
                    ]);
                }
            }

            $logins = is_array($payload['logins'] ?? null) ? $payload['logins'] : [];
            $active = $payload['active'] ?? true;

            foreach ($logins as $i => $loginData) {
                if (!is_array($loginData)) {
                    continue;
                }
                $email = trim((string) ($loginData['email'] ?? ''));
                $tempPass = (string) ($loginData['temp_pass'] ?? '');

                if ($email === '') {
                    continue;
                }

                $passwordHash = PasswordHelper::hash($tempPass !== '' ? $tempPass : bin2hex(random_bytes(16)));

                try {
                    $userId = $this->userRepo->create([
                        'email' => $email,
                        'password_hash' => $passwordHash,
                        'portal_enabled' => (bool) $active,
                    ]);
                } catch (\PDOException $e) {
                    throw $this->reclassifyUniqueViolation(
                        $e,
                        "logins.{$i}.email",
                        'Tento e-mail už v systému existuje — pro nový přístup zvolte jiný'
                    );
                }

                // Link user to all companies (or specific ones based on restriction)
                $restriction = $loginData['restriction'] ?? 'all';
                $allowedIcos = is_array($loginData['allowed_icos'] ?? null) ? $loginData['allowed_icos'] : [];

                if ($restriction === 'all') {
                    foreach ($companyIds as $companyId) {
                        $this->companyUserRepo->create($companyId, $userId);
                    }
                } else {
                    foreach ($allowedIcos as $ico) {
                        if (isset($icoToCompanyId[$ico])) {
                            $this->companyUserRepo->create($icoToCompanyId[$ico], $userId);
                        }
                    }
                }
            }

            // 4. Create contacts and link to companies
            $contacts = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];

            foreach ($contacts as $contactData) {
                if (!is_array($contactData)) {
                    continue;
                }
                $name = trim((string) ($contactData['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $contactId = $this->clientContactRepo->create([
                    'name' => $name,
                    'position' => $contactData['role'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'email' => $contactData['email'] ?? null,
                ]);

                $scope = $contactData['scope'] ?? 'global';
                if ($scope === 'global') {
                    foreach ($companyIds as $cid) {
                        $this->companyContactRepo->create($cid, $contactId);
                    }
                } else {
                    $icoId = $contactData['ico_id'] ?? null;
                    if ($icoId !== null && in_array((int) $icoId, $companyIds, true)) {
                        $this->companyContactRepo->create((int) $icoId, $contactId);
                    }
                }
            }

            // 5. Handle staff assignments
            // First, link employees to client via client_employees
            // Then optionally assign to specific locations via employee_locations
            $clientLocations = $this->locationRepo->findByClientId($clientDbId);
            $validLocationIds = array_map('intval', array_column($clientLocations, 'id'));

            $staff = $request->input('staff', []);
            if (!is_array($staff)) {
                $staff = [];
            }
            foreach ($staff as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $employeeId = (int) ($s['employee_id'] ?? $s['employeeId'] ?? 0);
                if ($employeeId <= 0) {
                    continue;
                }

                // Link employee to client (main relationship)
                if (!$this->clientEmployeeRepo->exists($clientDbId, $employeeId)) {
                    $this->clientEmployeeRepo->create($clientDbId, $employeeId);
                }

                // Handle optional location assignments
                $assignedObjects = $s['assigned_objects'] ?? $s['assignedObjects'] ?? [];
                if (!is_array($assignedObjects)) {
                    $assignedObjects = [];
                }
                $filteredLocations = array_filter(
                    array_map('intval', $assignedObjects),
                    fn($locId) => in_array($locId, $validLocationIds, true)
                );
                $this->employeeLocationRepo->syncEmployeeLocations(
                    $employeeId,
                    array_values($filteredLocations)
                );
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        $client = $this->clientRepo->findById($clientDbId);

        Response::created($client, 'Klient byl vytvořen');
    }

    public function updateClient(Request $request): void
    {
        $clientId = $request->param('id');
        $client = $this->clientRepo->findByClientId($clientId);

        if (!$client) {
            throw new NotFoundException('Klient nebyl nalezen');
        }

        $id = (int) $client['id'];

        $payload = $request->all();
        $errors = $this->validateClientPayload($payload, $id);
        if (!empty($errors)) {
            throw new ValidationException('Zkontrolujte prosím vyplněné údaje', $errors);
        }

        $data = [];
        if (array_key_exists('display_name', $payload) && is_string($payload['display_name'])) {
            $data['display_name'] = trim($payload['display_name']);
        }

        $db = \App\Config\Database::getConnection();
        $db->beginTransaction();

        try {
            if (!empty($data)) {
                $this->clientRepo->update($id, $data);
            }

            $companies = $this->companyRepo->findByClientId($id);
            $companyIds = array_map('intval', array_column($companies, 'id'));
            $icoToCompanyId = [];
            foreach ($companies as $company) {
                $icoToCompanyId[$company['registration_number']] = (int) $company['id'];
            }

            $icos = $request->input('icos', []);
            if (!is_array($icos)) {
                $icos = [];
            }

            foreach ($icos as $icoData) {
                if (!is_array($icoData)) {
                    continue;
                }
                $ico = $icoData['ico'] ?? '';
                if ($ico === '' || !isset($icoToCompanyId[$ico])) {
                    continue;
                }
                $companyId = $icoToCompanyId[$ico];

                if (array_key_exists('idoklad_sync_enabled', $icoData)) {
                    $this->companyRepo->update($companyId, [
                        'idoklad_sync_enabled' => (bool) $icoData['idoklad_sync_enabled'],
                    ]);
                }

                $this->locationRepo->deleteByCompanyId($companyId);

                $objects = $icoData['objects'] ?? [];
                if (!is_array($objects)) {
                    $objects = [];
                }
                foreach ($objects as $obj) {
                    if (!is_array($obj)) {
                        continue;
                    }
                    $this->locationRepo->create([
                        'company_id' => $companyId,
                        'name' => $obj['name'] ?? '',
                        'address' => $obj['address'] ?? null,
                        'latitude' => $obj['lat'] ?? null,
                        'longitude' => $obj['lng'] ?? null,
                    ]);
                }
            }

            $contacts = $request->input('contacts', []);
            if (!is_array($contacts)) {
                $contacts = [];
            }

            $this->clientContactRepo->deleteByCompanyIds($companyIds);
            foreach ($companyIds as $cid) {
                $this->companyContactRepo->deleteByCompanyId($cid);
            }

            foreach ($contacts as $contactData) {
                if (!is_array($contactData)) {
                    continue;
                }
                $name = trim((string) ($contactData['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $contactId = $this->clientContactRepo->create([
                    'name' => $name,
                    'position' => $contactData['role'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'email' => $contactData['email'] ?? null,
                ]);

                $scope = $contactData['scope'] ?? 'global';
                if ($scope === 'global') {
                    foreach ($companyIds as $cid) {
                        $this->companyContactRepo->create($cid, $contactId);
                    }
                } else {
                    $icoId = $contactData['ico_id'] ?? null;
                    if ($icoId !== null && in_array((int) $icoId, $companyIds, true)) {
                        $this->companyContactRepo->create((int) $icoId, $contactId);
                    }
                }
            }

            // Handle staff assignments
            $clientLocations = $this->locationRepo->findByClientId($id);
            $validLocationIds = array_map('intval', array_column($clientLocations, 'id'));

            $staff = $request->input('staff', []);
            if (!is_array($staff)) {
                $staff = [];
            }

            // Collect employee IDs and sync client_employees
            $staffEmployeeIds = [];
            foreach ($staff as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $employeeId = (int) ($s['employee_id'] ?? $s['employeeId'] ?? 0);
                if ($employeeId > 0) {
                    $staffEmployeeIds[] = $employeeId;
                }
            }
            $this->clientEmployeeRepo->syncClientEmployees($id, $staffEmployeeIds);

            // Handle location assignments for each staff member
            foreach ($staff as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $employeeId = (int) ($s['employee_id'] ?? $s['employeeId'] ?? 0);
                if ($employeeId <= 0) {
                    continue;
                }

                $assignedObjects = $s['assigned_objects'] ?? $s['assignedObjects'] ?? [];
                if (!is_array($assignedObjects)) {
                    $assignedObjects = [];
                }
                $filteredLocations = array_filter(
                    array_map('intval', $assignedObjects),
                    fn($locId) => in_array($locId, $validLocationIds, true)
                );
                $this->employeeLocationRepo->syncEmployeeLocations(
                    $employeeId,
                    array_values($filteredLocations)
                );
            }

            $this->syncClientLogins(
                clientCompanyIds: $companyIds,
                icoToCompanyId: $icoToCompanyId,
                loginsPayload: is_array($payload['logins'] ?? null) ? $payload['logins'] : [],
                portalEnabled: $request->input('active') !== null ? (bool) $request->input('active') : null
            );

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        $updated = $this->clientRepo->findById($id);

        Response::success($updated, 'Klient byl aktualizován');
    }

    /**
     * Reconcile the full set of login accounts attached to a client in one pass.
     *
     * Rows with a `user_id` are treated as edits (email, password, portal_enabled,
     * company scope). Rows without one are created. Existing accounts not present in
     * the payload are detached from this client's companies, and the login_accounts
     * row is deleted only when the account has zero remaining company_users links
     * across *all* clients — this keeps shared logins (if ever introduced) intact.
     *
     * $portalEnabled maps to the client-level "Aktivní" toggle, applied uniformly to
     * every login. Per-login portal_enabled divergence isn't exposed in the admin UI,
     * so round-tripping won't create or preserve mixed states.
     *
     * Caller is expected to run inside a transaction.
     *
     * @param int[] $clientCompanyIds
     * @param array<string,int> $icoToCompanyId
     * @param array<int,mixed> $loginsPayload
     */
    private function syncClientLogins(
        array $clientCompanyIds,
        array $icoToCompanyId,
        array $loginsPayload,
        ?bool $portalEnabled
    ): void {
        $currentUserIds = [];
        foreach ($clientCompanyIds as $cid) {
            foreach ($this->companyUserRepo->findByCompanyId($cid) as $link) {
                $currentUserIds[(int) $link['user_id']] = true;
            }
        }

        $keptUserIds = [];
        foreach ($loginsPayload as $i => $loginData) {
            if (!is_array($loginData)) {
                continue;
            }
            $email = trim((string) ($loginData['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $userId = isset($loginData['user_id']) && is_numeric($loginData['user_id'])
                ? (int) $loginData['user_id']
                : null;
            $tempPass = (string) ($loginData['temp_pass'] ?? '');
            $restriction = $loginData['restriction'] ?? 'all';
            $allowedIcos = is_array($loginData['allowed_icos'] ?? null) ? $loginData['allowed_icos'] : [];

            $targetCompanyIds = [];
            if ($restriction === 'all') {
                $targetCompanyIds = $clientCompanyIds;
            } else {
                foreach ($allowedIcos as $ico) {
                    if (isset($icoToCompanyId[$ico])) {
                        $targetCompanyIds[] = $icoToCompanyId[$ico];
                    }
                }
            }
            $targetCompanyIds = array_values(array_unique(array_map('intval', $targetCompanyIds)));

            // Validation rejects stale user_ids upfront; treat a miss here as a defensive
            // fallback (should never fire) rather than silently converting to a create.
            if ($userId !== null && !isset($currentUserIds[$userId])) {
                throw new ValidationException('Zkontrolujte prosím vyplněné údaje', [
                    "logins.{$i}.email" => ['Neznámý přihlašovací účet — obnovte stránku'],
                ]);
            }

            if ($userId !== null) {
                $userUpdate = ['email' => $email];
                if ($tempPass !== '') {
                    $userUpdate['password_hash'] = PasswordHelper::hash($tempPass);
                }
                if ($portalEnabled !== null) {
                    $userUpdate['portal_enabled'] = $portalEnabled;
                }
                try {
                    $this->userRepo->update($userId, $userUpdate);
                } catch (\PDOException $e) {
                    throw $this->reclassifyUniqueViolation(
                        $e,
                        "logins.{$i}.email",
                        'Tento e-mail už v systému existuje — pro nový přístup zvolte jiný'
                    );
                }
            } else {
                $passwordHash = PasswordHelper::hash($tempPass !== '' ? $tempPass : bin2hex(random_bytes(16)));
                try {
                    $userId = $this->userRepo->create([
                        'email' => $email,
                        'password_hash' => $passwordHash,
                        'portal_enabled' => $portalEnabled ?? true,
                    ]);
                } catch (\PDOException $e) {
                    throw $this->reclassifyUniqueViolation(
                        $e,
                        "logins.{$i}.email",
                        'Tento e-mail už v systému existuje — pro nový přístup zvolte jiný'
                    );
                }
            }

            // Sync company_users within this client's scope only, so a login shared with
            // another client (if that ever becomes a thing) keeps its foreign links.
            $currentCompaniesForUser = array_map('intval', $this->companyUserRepo->getCompanyIdsByUser($userId));
            $ownedNow = array_values(array_intersect($currentCompaniesForUser, $clientCompanyIds));
            foreach (array_diff($targetCompanyIds, $ownedNow) as $cid) {
                $this->companyUserRepo->create((int) $cid, $userId);
            }
            foreach (array_diff($ownedNow, $targetCompanyIds) as $cid) {
                $this->companyUserRepo->deleteByCompanyAndUser((int) $cid, $userId);
            }

            $keptUserIds[$userId] = true;
        }

        foreach (array_keys($currentUserIds) as $existingUserId) {
            if (isset($keptUserIds[$existingUserId])) {
                continue;
            }
            foreach ($clientCompanyIds as $cid) {
                $this->companyUserRepo->deleteByCompanyAndUser((int) $cid, $existingUserId);
            }
            if ($this->companyUserRepo->countUserCompanies($existingUserId) === 0) {
                $this->userRepo->delete($existingUserId);
            }
        }
    }

    public function deleteClient(Request $request): void
    {
        $clientId = $request->param('id');
        $client = $this->clientRepo->findByClientId($clientId);

        if (!$client) {
            throw new NotFoundException('Klient nebyl nalezen');
        }

        $id = (int) $client['id'];
        $this->clientRepo->delete($id);

        Response::success(null, 'Klient byl smazán');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // EMPLOYEES
    // ─────────────────────────────────────────────────────────────────────────────

    public function listEmployees(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $employees = $this->employeeRepo->findPaginated(
            $pagination['per_page'],
            $pagination['offset'],
            $search
        );
        $total = $this->employeeRepo->countAll($search);

        // Enrich with location assignments
        $enrichedEmployees = [];
        foreach ($employees as $emp) {
            $locations = $this->employeeLocationRepo->findByEmployeeId((int) $emp['id']);

            $enrichedEmployees[] = [
                'id' => $emp['id'],
                'first_name' => $emp['first_name'],
                'last_name' => $emp['last_name'],
                'email' => $emp['email'],
                'phone' => $emp['phone'],
                'personal_id' => $emp['personal_id'],
                'position' => $emp['position'],
                'photo_url' => $this->storage->resolveProxyUrl($emp['photo_url'] ?? null),
                'tenure_text' => $emp['tenure_text'],
                'bio' => $emp['bio'],
                'hobbies' => $emp['hobbies'],
                'contract_file' => $this->storage->resolveProxyUrl($emp['contract_file'] ?? null),
                'show_name' => (bool) $emp['show_name'],
                'show_photo' => (bool) $emp['show_photo'],
                'show_phone' => (bool) $emp['show_phone'],
                'show_email' => (bool) $emp['show_email'],
                'show_in_portal' => (bool) $emp['show_in_portal'],
                'show_role' => (bool) $emp['show_role'],
                'show_hobbies' => (bool) $emp['show_hobbies'],
                'show_tenure' => (bool) $emp['show_tenure'],
                'show_bio' => (bool) $emp['show_bio'],
                'location_count' => count($locations),
                'created_at' => $emp['created_at'],
            ];
        }

        Response::paginated($enrichedEmployees, $total, $pagination['page'], $pagination['per_page']);
    }

    public function getEmployee(Request $request): void
    {
        $id = (int) $request->param('id');
        $employee = $this->employeeRepo->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nebyl nalezen');
        }

        $employee['photo_url'] = $this->storage->resolveProxyUrl($employee['photo_url'] ?? null);
        $employee['contract_file'] = $this->storage->resolveProxyUrl($employee['contract_file'] ?? null);

        $locations = $this->employeeLocationRepo->findByEmployeeId($id);

        Response::success([
            'employee' => $employee,
            'locations' => $locations,
        ]);
    }

    public function createEmployee(Request $request): void
    {
        $payload = $request->all();
        $errors = $this->validateEmployeeFields($payload, null);
        if (!empty($errors)) {
            throw new ValidationException('Zkontrolujte prosím vyplněné údaje', $errors);
        }

        $data = [
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'email' => isset($payload['email']) && trim((string) $payload['email']) !== '' ? trim((string) $payload['email']) : null,
            'phone' => isset($payload['phone']) && trim((string) $payload['phone']) !== '' ? trim((string) $payload['phone']) : null,
            'personal_id' => isset($payload['personal_id']) && trim((string) $payload['personal_id']) !== '' ? trim((string) $payload['personal_id']) : null,
            'position' => isset($payload['position']) && trim((string) $payload['position']) !== '' ? trim((string) $payload['position']) : null,
        ];

        // employees.email has no UNIQUE constraint in schema — uniqueness is app-level only,
        // so there's no PDOException to catch here. A racing duplicate would slip through;
        // accepted because cross-request employee creation is rare and low-stakes.
        $employeeId = $this->employeeRepo->create($data);
        $employee = $this->employeeRepo->findById($employeeId);

        // Handle location assignments if provided
        $locationIds = $request->input('location_ids', []);
        if (!empty($locationIds)) {
            $this->employeeLocationRepo->syncEmployeeLocations($employeeId, array_map('intval', $locationIds));
        }

        Response::created($employee, 'Zaměstnanec byl vytvořen');
    }

    public function updateEmployee(Request $request): void
    {
        $id = (int) $request->param('id');
        $employee = $this->employeeRepo->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nebyl nalezen');
        }

        $data = $request->all();

        $errors = $this->validateEmployeeFields($data, $id);
        if (!empty($errors)) {
            throw new ValidationException('Zkontrolujte prosím vyplněné údaje', $errors);
        }

        // Filter allowed fields
        $allowedFields = [
            'first_name', 'last_name', 'email', 'phone', 'personal_id', 'position', 'photo_url',
            'tenure_text', 'bio', 'hobbies', 'contract_file',
            'show_name', 'show_photo', 'show_phone', 'show_email',
            'show_in_portal', 'show_role', 'show_hobbies', 'show_tenure', 'show_bio',
        ];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        // FE may echo the resolved URL back; always persist the bare R2 key.
        foreach (['photo_url', 'contract_file'] as $fileField) {
            if (array_key_exists($fileField, $updateData) && is_string($updateData[$fileField])) {
                $updateData[$fileField] = $this->storage->extractKey($updateData[$fileField]) ?: null;
            }
        }

        if (!empty($updateData)) {
            $this->employeeRepo->update($id, $updateData);
        }

        // Handle location assignments if provided
        if (isset($data['location_ids'])) {
            $this->employeeLocationRepo->syncEmployeeLocations($id, array_map('intval', $data['location_ids']));
        }

        $updated = $this->employeeRepo->findById($id);
        $updated['photo_url'] = $this->storage->resolveProxyUrl($updated['photo_url'] ?? null);
        $updated['contract_file'] = $this->storage->resolveProxyUrl($updated['contract_file'] ?? null);

        Response::success($updated, 'Zaměstnanec byl aktualizován');
    }

    /**
     * Validate a single-employee payload (create or update).
     *
     * @param array<string, int>|null $emailOwners Optional precomputed map of lowercased
     *     email → owning employee id. If provided, skips the per-row existsByEmail query.
     *     Used by bulk saves to avoid an N-query pattern.
     * @return array<string, array<int, string>>
     */
    private function validateEmployeeFields(array $data, ?int $updatingId, ?array $emailOwners = null): array
    {
        $errors = [];

        $firstName = isset($data['first_name']) ? trim((string) $data['first_name']) : '';
        $lastName = isset($data['last_name']) ? trim((string) $data['last_name']) : '';

        if ($firstName === '') {
            $errors['first_name'][] = 'Jméno je povinné';
        } elseif (mb_strlen($firstName) > 100) {
            $errors['first_name'][] = 'Jméno může mít nejvýše 100 znaků';
        }

        if ($lastName === '') {
            $errors['last_name'][] = 'Příjmení je povinné';
        } elseif (mb_strlen($lastName) > 100) {
            $errors['last_name'][] = 'Příjmení může mít nejvýše 100 znaků';
        }

        if (array_key_exists('email', $data) && is_string($data['email'])) {
            $email = trim($data['email']);
            if ($email !== '') {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['email'][] = 'E-mail není ve správném formátu (např. jmeno@firma.cz)';
                } elseif (mb_strlen($email) > 255) {
                    $errors['email'][] = 'E-mail může mít nejvýše 255 znaků';
                } else {
                    $conflict = $emailOwners !== null
                        ? ($emailOwners[mb_strtolower($email)] ?? null)
                        : ($this->employeeRepo->existsByEmail($email, $updatingId) ? -1 : null);
                    if ($conflict !== null && $conflict !== $updatingId) {
                        $errors['email'][] = 'Tento e-mail už používá jiný zaměstnanec';
                    }
                }
            }
        }

        if (array_key_exists('phone', $data) && is_string($data['phone']) && mb_strlen(trim($data['phone'])) > 20) {
            $errors['phone'][] = 'Telefon může mít nejvýše 20 znaků';
        }

        if (array_key_exists('personal_id', $data) && is_string($data['personal_id']) && mb_strlen(trim($data['personal_id'])) > 50) {
            $errors['personal_id'][] = 'Osobní ID může mít nejvýše 50 znaků';
        }

        if (array_key_exists('position', $data) && is_string($data['position']) && mb_strlen(trim($data['position'])) > 100) {
            $errors['position'][] = 'Pozice může mít nejvýše 100 znaků';
        }

        return $errors;
    }

    public function deleteEmployee(Request $request): void
    {
        $id = (int) $request->param('id');
        $employee = $this->employeeRepo->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nebyl nalezen');
        }

        $this->employeeRepo->delete($id);

        Response::success(null, 'Zaměstnanec byl smazán');
    }

    /**
     * Bulk save employees (create or update multiple).
     *
     * All validation happens upfront (before any DB writes) so that a single bad row
     * doesn't leave us half-saved. Errors use dot-paths (e.g., "2.email") so the FE
     * can highlight the exact row that failed.
     */
    public function saveEmployees(Request $request): void
    {
        $employees = $request->all();

        if (!is_array($employees) || empty($employees)) {
            throw new ValidationException('Nebyla poskytnuta žádná data zaměstnanců');
        }

        // Normalize camelCase → snake_case first so validation sees a consistent shape.
        $mappedEmployees = [];
        foreach ($employees as $emp) {
            if (!is_array($emp)) {
                continue;
            }
            $incomingPhoto = $emp['photoUrl'] ?? $emp['photo_url'] ?? null;
            $incomingContract = $emp['contractFile'] ?? $emp['contract_file'] ?? null;
            $mapped = [
                'first_name' => isset($emp['firstName']) ? (string) $emp['firstName']
                    : (isset($emp['first_name']) ? (string) $emp['first_name'] : ''),
                'last_name' => isset($emp['lastName']) ? (string) $emp['lastName']
                    : (isset($emp['last_name']) ? (string) $emp['last_name'] : ''),
                'email' => $emp['email'] ?? null,
                'phone' => $emp['phone'] ?? null,
                'personal_id' => $emp['personalId'] ?? $emp['personal_id'] ?? null,
                'position' => $emp['role'] ?? $emp['position'] ?? null,
                'photo_url' => is_string($incomingPhoto) ? ($this->storage->extractKey($incomingPhoto) ?: null) : null,
                'tenure_text' => $emp['tenureText'] ?? $emp['tenure_text'] ?? null,
                'bio' => $emp['bio'] ?? null,
                'hobbies' => $emp['hobbies'] ?? null,
                'contract_file' => is_string($incomingContract) ? ($this->storage->extractKey($incomingContract) ?: null) : null,
                'show_name' => (bool) ($emp['showName'] ?? $emp['show_name'] ?? true),
                'show_photo' => (bool) ($emp['showPhoto'] ?? $emp['show_photo'] ?? true),
                'show_phone' => (bool) ($emp['showPhone'] ?? $emp['show_phone'] ?? false),
                'show_email' => (bool) ($emp['showEmail'] ?? $emp['show_email'] ?? false),
                'show_in_portal' => (bool) ($emp['showInPortal'] ?? $emp['show_in_portal'] ?? false),
                'show_role' => (bool) ($emp['showRole'] ?? $emp['show_role'] ?? true),
                'show_hobbies' => (bool) ($emp['showHobbies'] ?? $emp['show_hobbies'] ?? false),
                'show_tenure' => (bool) ($emp['showTenure'] ?? $emp['show_tenure'] ?? true),
                'show_bio' => (bool) ($emp['showBio'] ?? $emp['show_bio'] ?? false),
            ];

            if (isset($emp['id']) && is_numeric($emp['id']) && (int) $emp['id'] > 0) {
                $mapped['id'] = (int) $emp['id'];
            }

            $mappedEmployees[] = $mapped;
        }

        // Batch lookup of existing employees that own any of the submitted emails, so we
        // avoid N queries in the per-row loop.
        $submittedEmails = [];
        foreach ($mappedEmployees as $emp) {
            $email = isset($emp['email']) ? trim((string) $emp['email']) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $submittedEmails[] = $email;
            }
        }
        $emailOwners = $this->employeeRepo->findIdsByEmails($submittedEmails);

        // Per-row validation — collect all errors with dot-path keys
        $errors = [];
        $seenEmailRow = [];

        foreach ($mappedEmployees as $i => $emp) {
            $rowErrors = $this->validateEmployeeFields($emp, $emp['id'] ?? null, $emailOwners);

            // Cross-row duplicate e-mail detection inside the same payload
            $email = isset($emp['email']) ? trim((string) $emp['email']) : '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $key = mb_strtolower($email);
                if (isset($seenEmailRow[$key])) {
                    $rowErrors['email'][] = 'Tento e-mail už máte v seznamu (řádek ' . ($seenEmailRow[$key] + 1) . ')';
                } else {
                    $seenEmailRow[$key] = $i;
                }
            }

            foreach ($rowErrors as $field => $messages) {
                $errors["{$i}.{$field}"] = $messages;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Zkontrolujte prosím vyplněné údaje', $errors);
        }

        // Trim strings once validation passed so we don't persist leading/trailing whitespace.
        foreach ($mappedEmployees as &$emp) {
            foreach (['first_name', 'last_name', 'email', 'phone', 'personal_id', 'position',
                      'tenure_text', 'bio', 'hobbies'] as $f) {
                if (isset($emp[$f]) && is_string($emp[$f])) {
                    $emp[$f] = trim($emp[$f]);
                    if ($emp[$f] === '' && $f !== 'first_name' && $f !== 'last_name') {
                        $emp[$f] = null;
                    }
                }
            }
        }
        unset($emp);

        $savedIds = $this->employeeRepo->saveAll($mappedEmployees);

        Response::success([
            'saved_count' => count($savedIds),
            'ids' => $savedIds,
        ], 'Zaměstnanci byli uloženi');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // FILE UPLOAD
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Upload a file and optionally persist the storage key to the database immediately.
     * Accepts optional form fields: entity_type, entity_id, field.
     *
     * We persist the R2 key (never the URL). URLs returned to the FE are stable
     * proxy URLs resolved via R2StorageService::resolveProxyUrl() on every read,
     * so nothing the FE receives can ever expire.
     */
    public function uploadFile(Request $request): void
    {
        $storageController = new StorageController();
        $result = $storageController->processUpload($request);

        $entityType = $request->input('entity_type');
        $entityId = $request->input('entity_id');
        $field = $request->input('field');

        if ($entityType && $entityId && $field) {
            $this->persistFileKey((string) $entityType, (int) $entityId, (string) $field, $result['key']);
        }

        Response::success($result, 'Soubor byl nahrán');
    }

    /**
     * Persist an uploaded file's storage key (or null to clear) to the relevant DB record.
     */
    private function persistFileKey(string $entityType, int $entityId, string $field, ?string $key): void
    {
        $allowed = [
            'employee' => ['photo_url', 'contract_file'],
            'company' => ['contract_pdf_path'],
            'staff_contact' => ['photo_url'],
        ];

        if (!isset($allowed[$entityType]) || !in_array($field, $allowed[$entityType], true)) {
            throw new ValidationException('Neplatný typ entity nebo pole pro uložení souboru');
        }

        switch ($entityType) {
            case 'employee':
                $this->employeeRepo->update($entityId, [$field => $key]);
                break;
            case 'company':
                $this->companyRepo->update($entityId, [$field => $key]);
                break;
            case 'staff_contact':
                $this->staffContactRepo->update($entityId, [$field => $key]);
                break;
        }
    }

    /**
     * Clear a file field on a DB record and delete the file from R2.
     * Expects JSON body: { entity_type, entity_id, field }
     */
    public function removeFile(Request $request): void
    {
        $entityType = $request->input('entity_type');
        $entityId = $request->input('entity_id');
        $field = $request->input('field');

        if (!$entityType || !$entityId || !$field) {
            throw new ValidationException('Chybí parametry entity_type, entity_id nebo field');
        }

        $stored = $this->getEntityFileValue((string) $entityType, (int) $entityId, (string) $field);

        $this->persistFileKey((string) $entityType, (int) $entityId, (string) $field, null);

        // Legacy rows can still hold a full URL — normalize to a key before deleting.
        if ($stored && !str_starts_with($stored, '/uploads/')) {
            try {
                $key = $this->storage->extractKey($stored);
                if ($key !== '') {
                    $this->storage->delete($key);
                }
            } catch (\Throwable $e) {
                // R2 deletion is best-effort; DB record is already cleared
            }
        }

        Response::success(null, 'Soubor byl odebrán');
    }

    /**
     * Look up the current raw value stored on an entity record (either a storage key or a legacy URL/path).
     */
    private function getEntityFileValue(string $entityType, int $entityId, string $field): ?string
    {
        $allowed = [
            'employee' => ['photo_url', 'contract_file'],
            'company' => ['contract_pdf_path'],
            'staff_contact' => ['photo_url'],
        ];

        if (!isset($allowed[$entityType]) || !in_array($field, $allowed[$entityType], true)) {
            return null;
        }

        $record = match ($entityType) {
            'employee' => $this->employeeRepo->findById($entityId),
            'company' => $this->companyRepo->findById($entityId),
            'staff_contact' => $this->staffContactRepo->findById($entityId),
            default => null,
        };

        return $record[$field] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────────────────────────────────────

    public function stats(Request $request): void
    {
        $totalClients = $this->clientRepo->countAll();
        $totalEmployees = $this->employeeRepo->countAll();

        // Count active vs inactive clients
        $clients = $this->clientRepo->findAll();
        $activeClients = 0;
        foreach ($clients as $client) {
            $companies = $this->companyRepo->findByClientId((int) $client['id']);
            foreach ($companies as $company) {
                $users = $this->companyUserRepo->findByCompanyId((int) $company['id']);
                foreach ($users as $userLink) {
                    $user = $this->userRepo->findById((int) $userLink['user_id']);
                    if ($user && $user['portal_enabled']) {
                        $activeClients++;
                        break 2;
                    }
                }
            }
        }

        Response::success([
            'clients' => [
                'total' => $totalClients,
                'active' => $activeClients,
                'inactive' => $totalClients - $activeClients,
            ],
            'employees' => [
                'total' => $totalEmployees,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // STAFF CONTACTS (Fajnuklid team displayed on client portal Kontakt page)
    // ─────────────────────────────────────────────────────────────────────────────

    public function listStaffContacts(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $items = $this->staffContactRepo->findPaginated(
            $pagination['per_page'],
            $pagination['offset'],
            $search
        );
        $items = array_map(fn ($i) => $this->projectStaffContact($i), $items);
        $total = $this->staffContactRepo->countAll($search);

        Response::paginated($items, $total, $pagination['page'], $pagination['per_page']);
    }

    public function getStaffContact(Request $request): void
    {
        $id = (int) $request->param('id');
        $contact = $this->staffContactRepo->findById($id);

        if (!$contact) {
            throw new NotFoundException('Kontakt nebyl nalezen');
        }

        Response::success($this->projectStaffContact($contact));
    }

    public function createStaffContact(Request $request): void
    {
        $data = $this->validate($request->all(), [
            'name'       => 'required|string|max:255',
            'position'   => 'string|max:100',
            'phone'      => 'string|max:20',
            'email'      => 'email|max:255',
            'photo_url'  => 'string|max:500',
            'sort_order' => 'integer',
        ]);

        if (!array_key_exists('sort_order', $data) || $data['sort_order'] === null) {
            $data['sort_order'] = $this->staffContactRepo->getMaxSortOrder() + 1;
        }

        if (isset($data['photo_url']) && is_string($data['photo_url'])) {
            $data['photo_url'] = $this->storage->extractKey($data['photo_url']) ?: null;
        }

        $id = $this->staffContactRepo->create($data);
        $created = $this->staffContactRepo->findById($id);

        Response::created($this->projectStaffContact($created), 'Kontakt byl vytvořen');
    }

    public function updateStaffContact(Request $request): void
    {
        $id = (int) $request->param('id');
        $existing = $this->staffContactRepo->findById($id);

        if (!$existing) {
            throw new NotFoundException('Kontakt nebyl nalezen');
        }

        $data = $this->validate($request->all(), [
            'name'       => 'string|max:255',
            'position'   => 'string|max:100',
            'phone'      => 'string|max:20',
            'email'      => 'email|max:255',
            'photo_url'  => 'string|max:500',
            'sort_order' => 'integer',
        ]);

        $allowed = ['name', 'position', 'phone', 'email', 'photo_url', 'sort_order'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('photo_url', $updateData) && is_string($updateData['photo_url'])) {
            $updateData['photo_url'] = $this->storage->extractKey($updateData['photo_url']) ?: null;
        }

        if (array_key_exists('email', $updateData)) {
            $this->staffLoginService->syncEmailIfChanged($id, $updateData['email']);
        }

        if (!empty($updateData)) {
            $this->staffContactRepo->update($id, $updateData);
        }

        $updated = $this->staffContactRepo->findById($id);

        Response::success($this->projectStaffContact($updated), 'Kontakt byl aktualizován');
    }

    public function reorderStaffContacts(Request $request): void
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            throw new ValidationException('Seznam ID je prázdný');
        }
        $ids = array_map('intval', $ids);
        $this->staffContactRepo->reorder($ids);
        Response::success(null, 'Pořadí bylo uloženo');
    }

    public function deleteStaffContact(Request $request): void
    {
        $id = (int) $request->param('id');
        $existing = $this->staffContactRepo->findById($id);

        if (!$existing) {
            throw new NotFoundException('Kontakt nebyl nalezen');
        }

        $userId = $existing['user_id'] !== null ? (int) $existing['user_id'] : null;
        $isActiveAdmin = $userId !== null && (int) ($existing['login_portal_enabled'] ?? 0) === 1;

        if ($isActiveAdmin && $this->userRepo->countActiveAdmins() <= 1) {
            throw new ValidationException('Nelze smazat posledního aktivního administrátora.');
        }

        $this->staffContactRepo->delete($id);
        $this->staffLoginService->disableLoginForUser($userId);

        Response::success(null, 'Kontakt byl smazán');
    }

    public function setStaffPassword(Request $request): void
    {
        $id = (int) $request->param('id');
        $existing = $this->staffContactRepo->findById($id);

        if (!$existing) {
            throw new NotFoundException('Kontakt nebyl nalezen');
        }

        $data = $this->validate($request->all(), [
            'password' => 'required|string|min:8|max:200',
        ]);

        $result = $this->staffLoginService->setPasswordForStaff($id, $data['password']);

        Response::success($result, 'Heslo bylo nastaveno');
    }

    public function revokeStaffLogin(Request $request): void
    {
        $id = (int) $request->param('id');
        $existing = $this->staffContactRepo->findById($id);

        if (!$existing) {
            throw new NotFoundException('Kontakt nebyl nalezen');
        }

        if ($existing['user_id'] === null) {
            Response::success(null, 'Účet již není aktivní');
            return;
        }

        if ((int) $existing['user_id'] === $request->getUserId()) {
            throw new ValidationException('Nemůžete zrušit přístup vlastnímu účtu.');
        }

        $isActiveAdmin = (int) ($existing['login_portal_enabled'] ?? 0) === 1;
        if ($isActiveAdmin && $this->userRepo->countActiveAdmins() <= 1) {
            throw new ValidationException('Nelze zrušit přístup poslednímu aktivnímu administrátorovi.');
        }

        $this->staffLoginService->revokeLogin($id);

        Response::success(null, 'Přístup byl zrušen');
    }

    private function projectStaffContact(array $row): array
    {
        $userId = $row['user_id'] ?? null;
        $portalEnabled = $row['login_portal_enabled'] ?? null;

        if ($userId === null) {
            $loginStatus = 'none';
        } elseif ((int) $portalEnabled === 1) {
            $loginStatus = 'active';
        } else {
            $loginStatus = 'revoked';
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'position' => $row['position'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'photo_url' => $this->storage->resolveProxyUrl($row['photo_url'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'login_status' => $loginStatus,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // MAINTENANCE REQUESTS
    // ─────────────────────────────────────────────────────────────────────────────

    public function listMaintenanceRequests(Request $request): void
    {
        $clientId = $request->query('clientId');
        $status = $request->query('status');
        if ($status === 'all') {
            $status = null;
        }

        $data = $this->maintenanceRequestService->listForAdmin(
            $clientId !== null ? (int) $clientId : null,
            $status
        );

        Response::success($data);
    }

    public function getMaintenanceRequest(Request $request): void
    {
        $id = (int) $request->param('id');
        $data = $this->maintenanceRequestService->getForAdmin($id);
        Response::success($data);
    }

    public function updateMaintenanceRequest(Request $request): void
    {
        $id = (int) $request->param('id');
        $user = $request->getUser();
        $adminUserId = (int) ($user['id'] ?? 0);
        $adminName = $user['email'] ?? 'Fajn Úklid';

        $data = $this->maintenanceRequestService->adminUpdate($id, $adminUserId, $adminName, $request->getBody());
        Response::success($data, 'Žádost byla aktualizována');
    }

    public function addMaintenanceRequestActivity(Request $request): void
    {
        $id = (int) $request->param('id');
        $user = $request->getUser();
        $adminUserId = (int) ($user['id'] ?? 0);
        $adminName = $user['email'] ?? 'Fajn Úklid';

        $message = (string) $request->input('message', '');
        $isInternal = (bool) $request->input('internal', false);

        $data = $this->maintenanceRequestService->adminAddActivity($id, $adminUserId, $adminName, $message, $isInternal);
        Response::success($data, 'Komentář byl přidán');
    }

    public function deleteMaintenanceRequest(Request $request): void
    {
        $id = (int) $request->param('id');
        $this->maintenanceRequestService->adminDelete($id);
        Response::success(null, 'Žádost byla smazána');
    }
}
