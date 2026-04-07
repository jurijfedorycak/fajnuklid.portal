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

class PersonnelController extends Controller
{
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private EmployeeRepository $employeeRepo;
    private EmployeeLocationRepository $employeeLocationRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
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
                'ico_name' => $company['name'],
                'objects' => [],
            ];

            // Get locations for this company
            $locations = $this->locationRepo->findByCompanyId((int) $company['id']);

            foreach ($locations as $location) {
                $objectData = [
                    'id' => $location['id'],
                    'name' => $location['name'],
                    'address' => $location['address'],
                    'staff' => [],
                ];

                // Get employees assigned to this location
                $employeeAssignments = $this->employeeLocationRepo->findByLocationId((int) $location['id']);

                foreach ($employeeAssignments as $assignment) {
                    $employee = $this->employeeRepo->findById((int) $assignment['employee_id']);
                    if ($employee && $employee['show_name']) {
                        $objectData['staff'][] = [
                            'id' => $employee['id'],
                            'name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                            'role' => $employee['position'],
                            'show_role' => (bool) $employee['show_name'],
                            'show_tenure' => true,
                            'tenure' => null,
                            'bio' => null,
                            'show_bio' => false,
                            'hobbies' => null,
                            'show_hobbies' => false,
                            'photo_url' => $employee['show_photo'] ? $employee['photo_url'] : null,
                        ];
                    }
                }

                $companyData['objects'][] = $objectData;
            }

            $personnelByLocation[] = $companyData;
        }

        Response::success([
            'personnel_by_location' => $personnelByLocation,
        ]);
    }
}
