<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Integrations\IDokladClient;
use App\Repositories\IcoRepository;

class InvoiceService
{
    private IDokladClient $iDokladClient;
    private IcoRepository $icoRepository;

    public function __construct()
    {
        $this->iDokladClient = new IDokladClient();
        $this->icoRepository = new IcoRepository();
    }

    public function getInvoicesForClient(int $clientId, ?string $icoFilter = null): array
    {
        $icos = $this->icoRepository->findByClientId($clientId);

        if (empty($icos)) {
            return [];
        }

        // Filter by specific IČO if provided
        if ($icoFilter) {
            $icos = array_filter($icos, fn($ico) => $ico['ico'] === $icoFilter);
        }

        $allInvoices = [];

        foreach ($icos as $ico) {
            $invoices = $this->iDokladClient->getInvoicesByIco($ico['ico']);

            foreach ($invoices as $invoice) {
                $invoice['ico_name'] = $ico['name'];
                $allInvoices[] = $invoice;
            }
        }

        // Sort by date descending
        usort($allInvoices, function ($a, $b) {
            return strtotime($b['date_issued']) - strtotime($a['date_issued']);
        });

        return $allInvoices;
    }

    public function getInvoicePdf(int $clientId, string $invoiceId): array
    {
        // Validate invoice ID format
        if (!preg_match('/^\d+$/', $invoiceId)) {
            throw new NotFoundException('Faktura nenalezena');
        }

        // Verify that the invoice belongs to this client
        $icos = $this->icoRepository->findByClientId($clientId);

        if (empty($icos)) {
            throw new NotFoundException('Faktura nenalezena');
        }

        $icoNumbers = array_column($icos, 'ico');

        // Get invoice details to verify ownership
        $invoice = $this->iDokladClient->getInvoice($invoiceId);

        if (!$invoice || !in_array($invoice['partner_ico'], $icoNumbers, true)) {
            throw new NotFoundException('Faktura nenalezena');
        }

        $pdfContent = $this->iDokladClient->getInvoicePdf($invoiceId);

        return [
            'content' => $pdfContent,
            'filename' => "faktura-{$invoice['document_number']}.pdf"
        ];
    }
}
