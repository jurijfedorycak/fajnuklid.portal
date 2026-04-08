<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\LocationRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\EmployeeLocationRepository;
use App\Repositories\ClientEmployeeRepository;

class PersonnelController extends Controller
{
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private EmployeeRepository $employeeRepo;
    private EmployeeLocationRepository $employeeLocationRepo;
    private ClientEmployeeRepository $clientEmployeeRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
        $this->clientEmployeeRepo = new ClientEmployeeRepository();
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

            // Get all employees assigned to the client this company belongs to.
            // Granular per-location assignment via employee_locations is optional;
            // if absent, every location of the company shows the full client roster.
            $clientEmployees = $this->clientEmployeeRepo->findByClientId((int) $company['client_id']);

            $clientStaff = [];
            foreach ($clientEmployees as $employee) {
                if (!$employee['show_in_portal'] || !$employee['show_name']) {
                    continue;
                }
                $clientStaff[(int) $employee['employee_id']] = $this->mapEmployee($employee);
            }

            // Get locations for this company
            $locations = $this->locationRepo->findByCompanyId((int) $company['id']);

            foreach ($locations as $location) {
                $objectData = [
                    'id' => $location['id'],
                    'name' => $location['name'],
                    'address' => $location['address'],
                    'staff' => [],
                ];

                // Prefer granular employee_locations assignments when present
                $assignments = $this->employeeLocationRepo->findByLocationId((int) $location['id']);
                if (!empty($assignments)) {
                    foreach ($assignments as $assignment) {
                        $employee = $this->employeeRepo->findById((int) $assignment['employee_id']);
                        if ($employee && $employee['show_in_portal'] && $employee['show_name']) {
                            $objectData['staff'][] = $this->mapEmployee($employee);
                        }
                    }
                } else {
                    // Fall back to client-level employee roster
                    $objectData['staff'] = array_values($clientStaff);
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
            'photoUrl' => !empty($employee['show_photo']) ? ($employee['photo_url'] ?? null) : null,
        ];
    }
}
