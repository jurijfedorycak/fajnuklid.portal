<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyUserRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\EmployeeLocationRepository;
use App\Repositories\UserRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ClientContactRepository;
use App\Helpers\PasswordHelper;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

class AdminController extends Controller
{
    private ClientRepository $clientRepo;
    private CompanyRepository $companyRepo;
    private CompanyUserRepository $companyUserRepo;
    private EmployeeRepository $employeeRepo;
    private EmployeeLocationRepository $employeeLocationRepo;
    private UserRepository $userRepo;
    private LocationRepository $locationRepo;
    private ClientContactRepository $clientContactRepo;

    public function __construct()
    {
        $this->clientRepo = new ClientRepository();
        $this->companyRepo = new CompanyRepository();
        $this->companyUserRepo = new CompanyUserRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
        $this->userRepo = new UserRepository();
        $this->locationRepo = new LocationRepository();
        $this->clientContactRepo = new ClientContactRepository();
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

            return [
                'id' => (int) $company['id'],
                'ico' => $company['registration_number'] ?? '',
                'officialName' => $company['name'] ?? '',
                'freshqrEnabled' => false,
                'billingModel' => 'hourly',
                'contractUploaded' => false,
                'contractFile' => null,
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

        // Load staff (employees assigned to client's locations)
        $employees = $this->employeeLocationRepo->findEmployeesByClientId($id);
        $locationMappings = $this->employeeLocationRepo->getLocationIdsByClientEmployees($id);
        $staff = array_map(function ($emp) use ($locationMappings) {
            $employeeId = (int) $emp['id'];
            return [
                'id' => $employeeId,
                'employeeId' => $employeeId,
                'name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')),
                'role' => $emp['position'] ?? '',
                'phone' => $emp['phone'] ?? '',
                'tenure' => $emp['tenure_text'] ?? '',
                'bio' => $emp['bio'] ?? '',
                'assignedObjects' => $locationMappings[$employeeId] ?? [],
            ];
        }, $employees);

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

        $clientId = $this->clientRepo->create($data);
        $client = $this->clientRepo->findById($clientId);

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

        $this->clientRepo->update($id, $data);
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
                'position' => $emp['position'],
                'photo_url' => $emp['photo_url'],
                'tenure_text' => $emp['tenure_text'],
                'bio' => $emp['bio'],
                'hobbies' => $emp['hobbies'],
                'contract_file' => $emp['contract_file'],
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
            'first_name', 'last_name', 'email', 'phone', 'position', 'photo_url',
            'tenure_text', 'bio', 'hobbies', 'contract_file',
            'show_name', 'show_photo', 'show_phone', 'show_email',
            'show_in_portal', 'show_role', 'show_hobbies', 'show_tenure', 'show_bio',
        ];
        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (!empty($updateData)) {
            $this->employeeRepo->update($id, $updateData);
        }

        // Handle location assignments if provided
        if (isset($data['location_ids'])) {
            $this->employeeLocationRepo->syncEmployeeLocations($id, array_map('intval', $data['location_ids']));
        }

        $updated = $this->employeeRepo->findById($id);

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
            $mapped = [
                'first_name' => $emp['firstName'] ?? $emp['first_name'] ?? '',
                'last_name' => $emp['lastName'] ?? $emp['last_name'] ?? '',
                'email' => $emp['email'] ?? null,
                'phone' => $emp['phone'] ?? null,
                'position' => $emp['role'] ?? $emp['position'] ?? null,
                'photo_url' => $emp['photoUrl'] ?? $emp['photo_url'] ?? null,
                'tenure_text' => $emp['tenureText'] ?? $emp['tenure_text'] ?? null,
                'bio' => $emp['bio'] ?? null,
                'hobbies' => $emp['hobbies'] ?? null,
                'contract_file' => $emp['contractFile'] ?? $emp['contract_file'] ?? null,
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
     * Upload a file (photo or contract).
     */
    public function uploadFile(Request $request): void
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('Soubor nebyl nahrán nebo došlo k chybě při nahrávání');
        }

        $file = $_FILES['file'];
        $folder = $request->input('folder', 'uploads');

        // Validate folder
        $allowedFolders = ['employee-photos', 'employee-contracts'];
        if (!in_array($folder, $allowedFolders, true)) {
            throw new ValidationException('Neplatná složka pro nahrání');
        }

        // Validate file type based on folder
        $allowedMimes = [];
        $maxSize = 5 * 1024 * 1024; // 5MB default

        if ($folder === 'employee-photos') {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB for photos
        } elseif ($folder === 'employee-contracts') {
            $allowedMimes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
            $maxSize = 10 * 1024 * 1024; // 10MB for contracts
        }

        // Check file size
        if ($file['size'] > $maxSize) {
            throw new ValidationException('Soubor je příliš velký. Maximum: ' . ($maxSize / 1024 / 1024) . ' MB');
        }

        // Check MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new ValidationException('Nepodporovaný typ souboru');
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
        $uniqueName = $safeName . '_' . uniqid() . '.' . $extension;

        // Create upload directory if it doesn't exist
        $uploadDir = dirname(__DIR__) . '/uploads/' . $folder;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $targetPath = $uploadDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new ValidationException('Nepodařilo se uložit soubor');
        }

        // Return the URL
        $baseUrl = rtrim(getenv('APP_URL') ?: '', '/');
        $url = $baseUrl . '/uploads/' . $folder . '/' . $uniqueName;

        Response::success(['url' => $url], 'Soubor byl nahrán');
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
}
