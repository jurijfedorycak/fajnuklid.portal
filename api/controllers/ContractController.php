<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\StaffContactRepository;
use App\Services\R2StorageService;
use App\Exceptions\NotFoundException;

class ContractController extends Controller
{
    private CompanyRepository $companyRepo;
    private StaffContactRepository $staffContactRepo;
    private R2StorageService $storage;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->staffContactRepo = new StaffContactRepository();
        $this->storage = new R2StorageService();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies
        $companies = $this->companyRepo->findByUserId($userId);

        // Use the first company as the primary contract source.
        // Multi-company support can iterate via separate calls when added.
        $primary = $companies[0] ?? null;

        $contractStored = $primary['contract_pdf_path'] ?? null;
        $contract = [
            'contractsEnabled' => $primary !== null,
            'hasPdf' => $primary !== null && !empty($contractStored),
            'companyId' => $primary['id'] ?? null,
            'companyName' => $primary['name'] ?? null,
            'registrationNumber' => $primary['registration_number'] ?? null,
            // basename on a raw URL would include the signed-request query string — normalize first.
            'filename' => !empty($contractStored)
                ? basename($this->storage->extractKey($contractStored))
                : null,
            'uploadedAt' => $primary['updated_at'] ?? null,
            'startDate' => $primary['contract_start_date'] ?? null,
            'endDate' => $primary['contract_end_date'] ?? null,
        ];

        // Get Fajnuklid contact for missing-contract situation
        $staffContacts = $this->staffContactRepo->findAll();
        if (!empty($staffContacts)) {
            $contract['contact'] = [
                'name' => $staffContacts[0]['name'],
                'phone' => $staffContacts[0]['phone'],
                'email' => $staffContacts[0]['email'],
            ];
        }

        Response::success($contract);
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

        $isLocalPath = str_starts_with($filePath, '/') || (bool) preg_match('/^[A-Za-z]:/', $filePath);

        if ($isLocalPath) {
            $realPath = realpath($filePath);
            $uploadsDir = realpath(dirname(__DIR__) . '/uploads');
            if ($realPath === false || $uploadsDir === false || !str_starts_with($realPath, $uploadsDir)) {
                throw new NotFoundException('Soubor smlouvy nebyl nalezen');
            }
            $content = file_get_contents($realPath);
            $filename = basename($realPath);
        } else {
            // Legacy rows may hold a full URL instead of the bare key; normalize first.
            $key = $this->storage->extractKey($filePath);
            $content = $this->storage->getContent($key);
            $filename = basename($key);
        }

        Response::pdf($content, $filename);
    }
}
