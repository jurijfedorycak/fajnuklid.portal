<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\ContractService;

class ContractController extends Controller
{
    private ContractService $contractService;

    public function __construct()
    {
        $this->contractService = new ContractService();
    }

    public function index(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $contracts = $this->contractService->getContractsForClient($clientId);

        Response::success($contracts);
    }

    public function downloadPdf(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $ico = $request->param('ico');

        $pdf = $this->contractService->getContractPdf($clientId, $ico);

        Response::pdf($pdf['content'], $pdf['filename']);
    }
}
