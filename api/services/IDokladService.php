<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\IDokladClient;
use App\Repositories\CompanyRepository;
use App\Repositories\InvoiceRepository;

class IDokladService
{
    private IDokladClient $client;
    private InvoiceRepository $invoiceRepo;
    private CompanyRepository $companyRepo;

    public function __construct()
    {
        $this->client = new IDokladClient();
        $this->invoiceRepo = new InvoiceRepository();
        $this->companyRepo = new CompanyRepository();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function syncInvoicesForCompany(int $companyId): array
    {
        $company = $this->companyRepo->findById($companyId);

        if ($company === null) {
            return [
                'success' => false,
                'message' => 'Firma nebyla nalezena',
                'synced' => 0,
            ];
        }

        $ico = $company['registration_number'] ?? '';

        if ($ico === '') {
            return [
                'success' => false,
                'message' => 'Firma nemá IČO',
                'synced' => 0,
            ];
        }

        if (!$this->client->isConfigured()) {
            return [
                'success' => false,
                'message' => 'iDoklad není nakonfigurován',
                'synced' => 0,
            ];
        }

        $this->client->resetLastError();
        $invoices = $this->client->getAllInvoicesByIco($ico);
        $apiError = $this->client->getLastError();

        if ($apiError !== null) {
            $httpCode = (int) ($apiError['http_code'] ?? 0);
            $context = (string) ($apiError['context'] ?? '');
            // response_body is only safe to show directly for contexts where we
            // craft the Czech message ourselves; for everything else it may be
            // raw API JSON / cURL output and must not be surfaced to users.
            $safeContexts = ['input validation', 'contact lookup'];

            if ($httpCode > 0) {
                $message = 'Volání iDoklad API selhalo (HTTP ' . $httpCode . ')';
            } elseif (in_array($context, $safeContexts, true)) {
                $message = $apiError['response_body'] ?? 'Chyba při synchronizaci iDokladu';
            } else {
                $message = 'Chyba při synchronizaci iDokladu';
            }

            return [
                'success' => false,
                'message' => $message,
                'synced' => 0,
                'error_details' => $apiError,
            ];
        }

        if (empty($invoices)) {
            return [
                'success' => true,
                'message' => 'Žádné faktury k synchronizaci',
                'synced' => 0,
            ];
        }

        $syncedCount = 0;
        $rowErrors = [];

        foreach ($invoices as $idokladInvoice) {
            $mapped = IDokladClient::mapIdokladInvoice($idokladInvoice, $companyId);
            try {
                $this->invoiceRepo->upsertFromIdoklad($mapped);
                $syncedCount++;
            } catch (\Throwable $e) {
                $rowErrors[] = [
                    'idoklad_id' => $mapped['idoklad_id'] ?? null,
                    'document_number' => $mapped['document_number'] ?? null,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if (!empty($rowErrors)) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Synchronizováno %d z %d faktur; %d selhalo při zápisu do DB',
                    $syncedCount,
                    count($invoices),
                    count($rowErrors)
                ),
                'synced' => $syncedCount,
                'error_details' => [
                    'context' => 'DB upsert',
                    'row_errors' => $rowErrors,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => "Synchronizováno $syncedCount faktur",
            'synced' => $syncedCount,
        ];
    }

    public function syncAllEnabledCompanies(): array
    {
        if (!$this->client->isConfigured()) {
            return [
                'success' => false,
                'message' => 'iDoklad není nakonfigurován',
                'total_synced' => 0,
                'companies' => [],
            ];
        }

        $companies = $this->companyRepo->findAllWithIdokladSyncEnabled();
        $totalSynced = 0;
        $results = [];

        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            try {
                $result = $this->syncInvoicesForCompany($companyId);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'iDoklad sync failed for company %d (%s): %s',
                    $companyId,
                    $company['registration_number'] ?? '',
                    $e->getMessage()
                ));
                $result = [
                    'success' => false,
                    'message' => 'Výjimka při synchronizaci: ' . $e->getMessage(),
                    'synced' => 0,
                ];
            }
            $totalSynced += $result['synced'];
            $results[] = [
                'company_id' => $companyId,
                'company_name' => $company['name'],
                'ico' => $company['registration_number'],
                'synced' => $result['synced'],
                'success' => $result['success'],
                'message' => $result['message'],
            ];
        }

        return [
            'success' => true,
            'total_synced' => $totalSynced,
            'company_count' => count($companies),
            'companies' => $results,
        ];
    }

    public function getInvoicesForUser(int $userId, ?string $ico = null): array
    {
        $invoices = $this->invoiceRepo->findByUserId($userId, $ico);

        // Calculate days relative to today for each invoice
        $today = new \DateTime('today');

        return array_map(function ($invoice) use ($today) {
            $dueDate = new \DateTime($invoice['date_due']);
            $diff = $today->diff($dueDate);
            $daysRelative = (int) $diff->format('%r%a');

            return [
                'id' => $invoice['document_number'],
                'dbId' => (int) $invoice['id'],
                'idokladId' => (int) $invoice['idoklad_id'],
                'issued' => $invoice['date_issued'],
                'due' => $invoice['date_due'],
                'amount' => (float) $invoice['total_amount'],
                'currency' => $invoice['currency_code'],
                'varSymbol' => $invoice['variable_symbol'],
                'status' => $invoice['payment_status'],
                'daysRelative' => $daysRelative,
                'companyName' => $invoice['company_name'] ?? null,
                'ico' => $invoice['registration_number'] ?? null,
            ];
        }, $invoices);
    }

    public function getTotalsForUser(int $userId, ?string $ico = null): array
    {
        return $this->invoiceRepo->getTotalsForUser($userId, $ico);
    }

    public function getInvoicePdf(int $invoiceDbId, int $userId): ?string
    {
        // Verify user owns the invoice
        if (!$this->invoiceRepo->userOwnsInvoice($userId, $invoiceDbId)) {
            return null;
        }

        $invoice = $this->invoiceRepo->findById($invoiceDbId);

        if ($invoice === null) {
            return null;
        }

        $idokladId = (int) $invoice['idoklad_id'];

        return $this->client->getInvoicePdf($idokladId);
    }

    public function getLastPdfError(): ?array
    {
        return $this->client->getLastError();
    }

    public function getInvoiceFilename(int $invoiceDbId): string
    {
        $invoice = $this->invoiceRepo->findById($invoiceDbId);

        if ($invoice === null) {
            return 'faktura.pdf';
        }

        $documentNumber = $invoice['document_number'] ?? 'faktura';
        // Sanitize filename
        $safeNumber = preg_replace('/[^a-zA-Z0-9_-]/', '_', $documentNumber);

        return "faktura_{$safeNumber}.pdf";
    }

    public function getLastSyncTime(int $companyId): ?string
    {
        return $this->invoiceRepo->getLastSyncTime($companyId);
    }
}
