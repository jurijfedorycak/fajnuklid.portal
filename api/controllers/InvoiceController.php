<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\InvoiceService;

class InvoiceController extends Controller
{
    private InvoiceService $invoiceService;

    public function __construct()
    {
        $this->invoiceService = new InvoiceService();
    }

    public function index(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $icoFilter = $request->query('ico');

        $invoices = $this->invoiceService->getInvoicesForClient($clientId, $icoFilter);

        Response::success($invoices);
    }

    public function downloadPdf(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $invoiceId = $request->param('id');

        $pdf = $this->invoiceService->getInvoicePdf($clientId, $invoiceId);

        Response::pdf($pdf['content'], $pdf['filename']);
    }
}
