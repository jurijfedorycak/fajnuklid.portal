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
use App\Repositories\StaffContactRepository;

class DashboardController extends Controller
{
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private EmployeeRepository $employeeRepo;
    private EmployeeLocationRepository $employeeLocationRepo;
    private StaffContactRepository $staffContactRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->employeeLocationRepo = new EmployeeLocationRepository();
        $this->staffContactRepo = new StaffContactRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies
        $companies = $this->companyRepo->findByUserId($userId);

        // Get all locations for user
        $locations = $this->locationRepo->findByUserId($userId);
        $locationIds = array_column($locations, 'id');

        // Count personnel assigned to user's locations
        $personnelCount = 0;
        foreach ($locationIds as $locationId) {
            $employees = $this->employeeLocationRepo->findByLocationId((int) $locationId);
            $personnelCount += count($employees);
        }

        // Get contract info for first company
        $contract = [
            'hasPdf' => false,
            'contractsEnabled' => false,
        ];
        if (!empty($companies)) {
            $firstCompany = $companies[0];
            $contract = [
                'hasPdf' => !empty($firstCompany['contract_pdf_path']),
                'contractsEnabled' => true,
                'startDate' => $firstCompany['contract_start_date'],
                'endDate' => $firstCompany['contract_end_date'],
            ];
        }

        // TODO: Fetch cleaning visits from external API
        // For now, return empty array - will be populated from external API
        $cleaningDays = [];

        // Get Fajnuklid staff contacts (limit to 2 for quick contact)
        $staffContacts = $this->staffContactRepo->findAll();
        $contacts = array_slice($staffContacts, 0, 2);
        $contacts = array_map(function ($c) {
            return [
                'name' => $c['name'],
                'role' => $c['position'],
                'phone' => $c['phone'],
                'email' => $c['email'],
            ];
        }, $contacts);

        // Build current user info
        $currentUser = [
            'id' => $user['id'],
            'email' => $user['email'],
            'displayName' => !empty($companies) ? $companies[0]['name'] : $user['email'],
            'activeIco' => !empty($companies) ? $companies[0]['registration_number'] : null,
            'clientId' => $user['client_id'] ?? null,
        ];

        Response::success([
            'currentUser' => $currentUser,
            'personnelCount' => $personnelCount,
            'contract' => $contract,
            'cleaningDays' => $cleaningDays,
            'contacts' => $contacts,
            'locations' => array_map(function ($l) {
                return [
                    'id' => $l['id'],
                    'name' => $l['name'],
                    'companyName' => $l['company_name'] ?? null,
                ];
            }, $locations),
        ]);
    }
}
