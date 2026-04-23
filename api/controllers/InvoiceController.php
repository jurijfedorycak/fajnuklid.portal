<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Services\IDokladService;
use App\Exceptions\NotFoundException;

class InvoiceController extends Controller
{
    private CompanyRepository $companyRepo;
    private IDokladService $idokladService;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->idokladService = new IDokladService();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];
        $selectedIco = $request->query('ico');

        // Get user's companies
        $companies = $this->companyRepo->findByUserId($userId);

        // Build IČO list for multi-company support
        $icos = [];
        $validIcos = [];
        foreach ($companies as $company) {
            $ico = $company['registration_number'] ?? '';
            $icos[] = [
                'ico' => $ico,
                'name' => $company['name'] ?? '',
            ];
            $validIcos[] = $ico;
        }

        // Determine active IČO - validate that selectedIco belongs to user's companies
        $activeIco = null;
        if ($selectedIco !== null && in_array($selectedIco, $validIcos, true)) {
            $activeIco = $selectedIco;
        }
        if ($activeIco === null && !empty($icos)) {
            $activeIco = $icos[0]['ico'];
        }

        // Get invoices from local cache
        $invoices = $this->idokladService->getInvoicesForUser($userId, $activeIco);
        $totals = $this->idokladService->getTotalsForUser($userId, $activeIco);

        // Get last sync time for active company
        $lastSync = null;
        if ($activeIco !== null) {
            foreach ($companies as $company) {
                if ($company['registration_number'] === $activeIco) {
                    $lastSync = $this->idokladService->getLastSyncTime((int) $company['id']);
                    break;
                }
            }
        }

        Response::success([
            'invoices' => $invoices,
            'icos' => $icos,
            'activeIco' => $activeIco,
            'totals' => $totals,
            'lastSync' => $lastSync,
            'isConfigured' => $this->idokladService->isConfigured(),
        ]);
    }

    public function downloadPdf(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];
        $id = (int) $request->param('id');

        $pdfContent = $this->idokladService->getInvoicePdf($id, $userId);

        if ($pdfContent === null) {
            throw new NotFoundException('PDF není dostupné');
        }

        $filename = $this->idokladService->getInvoiceFilename($id);

        Response::pdf($pdfContent, $filename);
    }

}
