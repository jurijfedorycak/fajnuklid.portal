<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\StaffContactRepository;
use App\Exceptions\NotFoundException;

class ContractController extends Controller
{
    private CompanyRepository $companyRepo;
    private StaffContactRepository $staffContactRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->staffContactRepo = new StaffContactRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies
        $companies = $this->companyRepo->findByUserId($userId);

        // Build contract data for each company
        $contracts = [];
        foreach ($companies as $company) {
            $contracts[] = [
                'company_id' => $company['id'],
                'company_name' => $company['name'],
                'registration_number' => $company['registration_number'],
                'has_pdf' => !empty($company['contract_pdf_path']),
                'contracts_enabled' => true,
                'filename' => $company['contract_pdf_path'] ? basename($company['contract_pdf_path']) : null,
                'uploaded_at' => $company['updated_at'],
                'start_date' => $company['contract_start_date'],
                'end_date' => $company['contract_end_date'],
            ];
        }

        // Get Fajnuklid contact for missing contract situation
        $staffContacts = $this->staffContactRepo->findAll();
        $primaryContact = !empty($staffContacts) ? [
            'name' => $staffContacts[0]['name'],
            'phone' => $staffContacts[0]['phone'],
            'email' => $staffContacts[0]['email'],
        ] : null;

        Response::success([
            'contracts' => $contracts,
            'contact' => $primaryContact,
        ]);
    }

    public function download(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];
        $companyId = (int) $request->query('company_id');

        if (!$companyId) {
            throw new NotFoundException('ID firmy nebylo zadáno');
        }

        // Verify user has access to this company
        $userCompanies = $this->companyRepo->findByUserId($userId);
        $company = null;
        foreach ($userCompanies as $c) {
            if ((int) $c['id'] === $companyId) {
                $company = $c;
                break;
            }
        }

        if (!$company) {
            throw new NotFoundException('Firma nebyla nalezena');
        }

        if (empty($company['contract_pdf_path'])) {
            throw new NotFoundException('Smlouva není k dispozici');
        }

        $filePath = $company['contract_pdf_path'];

        // In a real app, this would be a proper file path
        // For now, return a placeholder response indicating the file path
        if (!file_exists($filePath)) {
            throw new NotFoundException('Soubor smlouvy nebyl nalezen');
        }

        $content = file_get_contents($filePath);
        $filename = basename($filePath);

        Response::pdf($content, $filename);
    }
}
