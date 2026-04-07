<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;

class InvoiceController extends Controller
{
    private CompanyRepository $companyRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies
        $companies = $this->companyRepo->findByUserId($userId);

        // Build IČO list for multi-company support
        $icos = [];
        foreach ($companies as $company) {
            $icos[] = [
                'ico' => $company['registration_number'] ?? '',
                'name' => $company['name'] ?? '',
            ];
        }

        // TODO: Integrate with iDoklad or invoice table when available
        // For now, return empty invoice list
        Response::success([
            'invoices' => [],
            'icos' => $icos,
            'activeIco' => $icos[0]['ico'] ?? null,
            'totals' => [
                'all' => 0,
                'paid' => 0,
                'unpaid' => 0,
                'overdue' => 0,
                'debt' => 0,
            ],
        ]);
    }
}
