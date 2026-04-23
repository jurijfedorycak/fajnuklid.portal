<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\LocationRepository;
use App\Repositories\EmployeeLocationRepository;
use App\Repositories\ClientEmployeeRepository;
use App\Services\R2StorageService;

class PersonnelController extends Controller
{
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private EmployeeLocationRepository $employeeLocationRepo;
    private ClientEmployeeRepository $clientEmployeeRepo;
    private R2StorageService $storage;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
        $this->clientEmployeeRepo = new ClientEmployeeRepository();
        $this->storage = new R2StorageService();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies (IČOs)
        $companies = $this->companyRepo->findByUserId($userId);

        // Build personnel data grouped by company (IČO)
        $personnelByLocation = [];

        foreach ($companies as $company) {
            $companyData = [
                'ico' => $company['registration_number'],
                'icoName' => $company['name'],
                'objects' => [],
            ];

            $clientId = (int) $company['client_id'];

            // Client-level roster filtered by GDPR visibility flags.
            $clientEmployees = $this->clientEmployeeRepo->findByClientId($clientId);
            $visibleEmployees = [];
            foreach ($clientEmployees as $employee) {
                if (!$employee['show_in_portal'] || !$employee['show_name']) {
                    continue;
                }
                $visibleEmployees[(int) $employee['employee_id']] = $employee;
            }

            // Pinned locations per employee (prefetched to avoid N+1 inside the location loop).
            // An employee with no pinnings shows on every location of the client; pinning narrows them.
            $employeeLocationsMap = $this->employeeLocationRepo->getLocationIdsByClientEmployees($clientId);

            $locations = $this->locationRepo->findByCompanyId((int) $company['id']);

            foreach ($locations as $location) {
                $locationId = (int) $location['id'];
                $objectData = [
                    'id' => $location['id'],
                    'name' => $location['name'],
                    'address' => $location['address'],
                    'staff' => [],
                ];

                foreach ($visibleEmployees as $employeeId => $employee) {
                    $pinnedLocations = $employeeLocationsMap[$employeeId] ?? [];
                    if (empty($pinnedLocations) || in_array($locationId, $pinnedLocations, true)) {
                        $objectData['staff'][] = $this->mapEmployee($employee);
                    }
                }

                $companyData['objects'][] = $objectData;
            }

            $personnelByLocation[] = $companyData;
        }

        Response::success($personnelByLocation);
    }

    /**
     * Map an employee row to the public portal shape, honoring GDPR flags.
     */
    private function mapEmployee(array $employee): array
    {
        return [
            'id' => (int) ($employee['employee_id'] ?? $employee['id'] ?? 0),
            'name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
            'role' => !empty($employee['show_role']) ? ($employee['position'] ?? null) : null,
            'showRole' => (bool) ($employee['show_role'] ?? false),
            'tenure' => !empty($employee['show_tenure']) ? ($employee['tenure_text'] ?? null) : null,
            'showTenure' => (bool) ($employee['show_tenure'] ?? false),
            'bio' => !empty($employee['show_bio']) ? ($employee['bio'] ?? null) : null,
            'showBio' => (bool) ($employee['show_bio'] ?? false),
            'hobbies' => !empty($employee['show_hobbies']) ? ($employee['hobbies'] ?? null) : null,
            'showHobbies' => (bool) ($employee['show_hobbies'] ?? false),
            'photoUrl' => !empty($employee['show_photo']) ? $this->storage->resolveProxyUrl($employee['photo_url'] ?? null) : null,
        ];
    }
}
