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
use App\Services\MaintenanceRequestService;
use App\Services\R2StorageService;
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
    private R2StorageService $storage;

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
        $this->storage = new R2StorageService();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CLIENTS
    // ─────────────────────────────────────────────────────────────────────────────

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

        // Get login accounts
        $logins = [];
        $hasActiveLogin = false;
        foreach ($companies as $company) {
            $users = $this->companyUserRepo->findByCompanyId((int) $company['id']);
            foreach ($users as $userLink) {
                $user = $this->userRepo->findById((int) $userLink['user_id']);
                if ($user) {
                    if ($user['portal_enabled']) {
                        $hasActiveLogin = true;
                    }
                    $logins[] = [
                        'email' => $user['email'],
                        'portalEnabled' => (bool) $user['portal_enabled'],
                        'restriction' => 'all', // TODO: implement restriction logic
                        'allowedIcos' => [],
                    ];
                }
            }
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
        $data = $this->validate($request->all(), [
            'client_id' => 'required|string|max:50',
            'display_name' => 'required|string|max:255',
        ]);

        // Check if client_id already exists
        if ($this->clientRepo->existsByClientId($data['client_id'])) {
            throw new ValidationException('ID klienta již existuje', [
                'client_id' => ['Toto ID klienta je již použito'],
            ]);
        }

        $db = \App\Config\Database::getConnection();
        $db->beginTransaction();

        try {
            // 1. Create the client
            $clientDbId = $this->clientRepo->create($data);

            // 2. Create companies (ICOs)
            $icos = $request->input('icos', []);
            if (!is_array($icos)) {
                $icos = [];
            }

            $companyIds = [];
            $icoToCompanyId = [];

            foreach ($icos as $icoData) {
                if (!is_array($icoData)) {
                    continue;
                }
                $ico = $icoData['ico'] ?? '';
                $officialName = $icoData['official_name'] ?? '';

                if ($ico === '') {
                    continue;
                }

                // Check if ICO already exists
                if ($this->companyRepo->existsByRegistrationNumber($ico)) {
                    throw new ValidationException('IČO již existuje', [
                        'ico' => ["IČO {$ico} je již použito u jiného klienta"],
                    ]);
                }

                $incomingContract = $icoData['contract_file'] ?? null;
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

                $companyIds[] = $companyId;
                $icoToCompanyId[$ico] = $companyId;

                // Create locations (objects) for this company
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

            // 3. Create login accounts and link to companies
            $logins = $request->input('logins', []);
            if (!is_array($logins)) {
                $logins = [];
            }

            $active = $request->input('active', true);

            foreach ($logins as $loginData) {
                if (!is_array($loginData)) {
                    continue;
                }
                $email = $loginData['email'] ?? '';
                $tempPass = $loginData['temp_pass'] ?? '';

                if ($email === '') {
                    continue;
                }

                // Check if email already exists
                if ($this->userRepo->existsByEmail($email)) {
                    throw new ValidationException('E-mail již existuje', [
                        'email' => ["E-mail {$email} je již použit"],
                    ]);
                }

                // Hash the password
                $passwordHash = PasswordHelper::hash($tempPass ?: bin2hex(random_bytes(16)));

                $userId = $this->userRepo->create([
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'portal_enabled' => (bool) $active,
                ]);

                // Link user to all companies (or specific ones based on restriction)
                $restriction = $loginData['restriction'] ?? 'all';
                $allowedIcos = $loginData['allowed_icos'] ?? [];

                if ($restriction === 'all') {
                    // Link to all companies
                    foreach ($companyIds as $companyId) {
                        $this->companyUserRepo->create($companyId, $userId);
                    }
                } else {
                    // Link to specific ICOs
                    foreach ($allowedIcos as $ico) {
                        if (isset($icoToCompanyId[$ico])) {
                            $this->companyUserRepo->create($icoToCompanyId[$ico], $userId);
                        }
                    }
                }
            }

            // 4. Create contacts and link to companies
            $contacts = $request->input('contacts', []);
            if (!is_array($contacts)) {
                $contacts = [];
            }

            foreach ($contacts as $contactData) {
                if (!is_array($contactData)) {
                    continue;
                }
                $contactId = $this->clientContactRepo->create([
                    'name' => $contactData['name'] ?? '',
                    'position' => $contactData['role'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'email' => $contactData['email'] ?? null,
                ]);

                $scope = $contactData['scope'] ?? 'global';
                if ($scope === 'global') {
                    // Link to all companies
                    foreach ($companyIds as $cid) {
                        $this->companyContactRepo->create($cid, $contactId);
                    }
                } else {
                    // Link to specific ICO
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

        $data = $this->validate($request->all(), [
            'display_name' => 'string|max:255',
        ]);

        $db = \App\Config\Database::getConnection();
        $db->beginTransaction();

        try {
            $this->clientRepo->update($id, $data);

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
                $contactId = $this->clientContactRepo->create([
                    'name' => $contactData['name'] ?? '',
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

            $active = $request->input('active');
            if ($active !== null) {
                $portalEnabled = (bool) $active;
                foreach ($companyIds as $cid) {
                    $users = $this->companyUserRepo->findByCompanyId($cid);
                    foreach ($users as $userLink) {
                        $this->userRepo->update((int) $userLink['user_id'], [
                            'portal_enabled' => $portalEnabled,
                        ]);
                    }
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        $updated = $this->clientRepo->findById($id);

        Response::success($updated, 'Klient byl aktualizován');
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
        $data = $this->validate($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'email|max:255',
            'phone' => 'string|max:20',
            'personal_id' => 'string|max:50',
            'position' => 'string|max:100',
        ]);

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
     */
    public function saveEmployees(Request $request): void
    {
        $employees = $request->all();

        if (!is_array($employees) || empty($employees)) {
            throw new ValidationException('Nebyla poskytnuta žádná data zaměstnanců');
        }

        // Map camelCase from frontend to snake_case for database
        $mappedEmployees = [];
        foreach ($employees as $emp) {
            $incomingPhoto = $emp['photoUrl'] ?? $emp['photo_url'] ?? null;
            $incomingContract = $emp['contractFile'] ?? $emp['contract_file'] ?? null;
            $mapped = [
                'first_name' => $emp['firstName'] ?? $emp['first_name'] ?? '',
                'last_name' => $emp['lastName'] ?? $emp['last_name'] ?? '',
                'email' => $emp['email'] ?? null,
                'phone' => $emp['phone'] ?? null,
                'personal_id' => $emp['personalId'] ?? $emp['personal_id'] ?? null,
                'position' => $emp['role'] ?? $emp['position'] ?? null,
                // Normalize file fields — FE may echo resolved URLs; we persist the bare key.
                'photo_url' => is_string($incomingPhoto) ? ($this->storage->extractKey($incomingPhoto) ?: null) : null,
                'tenure_text' => $emp['tenureText'] ?? $emp['tenure_text'] ?? null,
                'bio' => $emp['bio'] ?? null,
                'hobbies' => $emp['hobbies'] ?? null,
                'contract_file' => is_string($incomingContract) ? ($this->storage->extractKey($incomingContract) ?: null) : null,
                // Explicitly cast boolean fields to ensure proper database values
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

            // Include ID if it exists (for updates)
            if (isset($emp['id']) && $emp['id'] > 0) {
                $mapped['id'] = (int) $emp['id'];
            }

            // Validate required fields
            if (empty($mapped['first_name']) || empty($mapped['last_name'])) {
                throw new ValidationException('Jméno a příjmení jsou povinné');
            }

            $mappedEmployees[] = $mapped;
        }

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
        foreach ($items as &$item) {
            $item['photo_url'] = $this->storage->resolveProxyUrl($item['photo_url'] ?? null);
        }
        unset($item);
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

        $contact['photo_url'] = $this->storage->resolveProxyUrl($contact['photo_url'] ?? null);

        Response::success($contact);
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
        $created['photo_url'] = $this->storage->resolveProxyUrl($created['photo_url'] ?? null);

        Response::created($created, 'Kontakt byl vytvořen');
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

        if (!empty($updateData)) {
            $this->staffContactRepo->update($id, $updateData);
        }

        $updated = $this->staffContactRepo->findById($id);
        $updated['photo_url'] = $this->storage->resolveProxyUrl($updated['photo_url'] ?? null);

        Response::success($updated, 'Kontakt byl aktualizován');
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

        $this->staffContactRepo->delete($id);

        Response::success(null, 'Kontakt byl smazán');
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
