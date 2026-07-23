<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\IDokladAccount;
use App\Helpers\IDokladAccountRegistry;
use App\Helpers\IDokladClient;
use App\Repositories\CompanyRepository;
use App\Repositories\InvoiceRepository;
use Closure;

class IDokladService
{
    /** @var IDokladAccount[] */
    private array $accounts;

    /** @var Closure(IDokladAccount): IDokladClient */
    private Closure $clientFactory;

    /** @var array<string, IDokladClient> Memoized clients keyed by account key. */
    private array $clientCache = [];

    private InvoiceRepository $invoiceRepo;
    private CompanyRepository $companyRepo;

    private ?IDokladClient $lastPdfClient = null;
    private ?array $lastPdfError = null;

    public function __construct()
    {
        $this->accounts = IDokladAccountRegistry::all();
        $this->clientFactory = static fn (IDokladAccount $account): IDokladClient => new IDokladClient($account);
        $this->invoiceRepo = new InvoiceRepository();
        $this->companyRepo = new CompanyRepository();
    }

    public function isConfigured(): bool
    {
        return $this->accounts !== [];
    }

    /**
     * One client per account, reused across companies within a sync run. Clients
     * are stateless apart from lastError, which every call resets before use.
     */
    private function clientFor(IDokladAccount $account): IDokladClient
    {
        return $this->clientCache[$account->key] ??= ($this->clientFactory)($account);
    }

    /**
     * Sync one company's invoices from every configured iDoklad account and merge
     * them. A customer's IČO may exist as a contact in only some of the accounts
     * (whichever legal entities have billed it), so an account where the contact
     * is missing simply contributes zero invoices — that is not a failure. Only a
     * genuine API/auth error against an account is reported.
     */
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

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'iDoklad není nakonfigurován',
                'synced' => 0,
            ];
        }

        $syncedCount = 0;
        $fetchedCount = 0;
        $rowErrors = [];
        $accountErrors = [];
        // A customer's IČO need only exist in some accounts. Track whether it was
        // resolved anywhere so we can still flag the misconfiguration case where
        // it matched no account at all (the sole signal in single-account setups).
        $contactFound = false;

        foreach ($this->accounts as $account) {
            $client = $this->clientFor($account);
            $client->resetLastError();
            $invoices = $client->getAllInvoicesByIco($ico);
            $apiError = $client->getLastError();

            if ($apiError !== null) {
                // Contact absent from this account is expected — skip it silently.
                if (IDokladClient::isContactNotFoundError($apiError)) {
                    continue;
                }
                $accountErrors[] = [
                    'account' => $account->key,
                    'error' => $apiError,
                ];
                continue;
            }

            // No error means the contact resolved in this account (it may simply
            // have no invoices since the sync-from date).
            $contactFound = true;
            $fetchedCount += count($invoices);

            foreach ($invoices as $idokladInvoice) {
                // Mapping stays inside the try: one malformed invoice payload
                // must surface as a row error, not abort the whole company sync.
                try {
                    $mapped = IDokladClient::mapIdokladInvoice($idokladInvoice, $companyId, $account->key);
                    $this->invoiceRepo->upsertFromIdoklad($mapped);
                    $syncedCount++;
                } catch (\Throwable $e) {
                    $rowErrors[] = [
                        'account' => $account->key,
                        'idoklad_id' => isset($idokladInvoice['Id']) ? (int) $idokladInvoice['Id'] : null,
                        'document_number' => $idokladInvoice['DocumentNumber'] ?? null,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        if (!empty($accountErrors) || !empty($rowErrors)) {
            return $this->buildFailureResult($accountErrors, $rowErrors, $syncedCount, $fetchedCount);
        }

        if (!$contactFound && $syncedCount === 0) {
            return [
                'success' => false,
                'message' => sprintf(
                    'IČO %s nebylo nalezeno jako kontakt v žádném z %d iDoklad účtů',
                    $ico,
                    count($this->accounts)
                ),
                'synced' => 0,
            ];
        }

        if ($syncedCount === 0) {
            return [
                'success' => true,
                'message' => 'Žádné faktury k synchronizaci',
                'synced' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => "Synchronizováno $syncedCount faktur",
            'synced' => $syncedCount,
        ];
    }

    /**
     * Merge per-account API failures and per-row DB failures into one user-facing
     * result so neither class of problem hides the other. The first API error
     * keeps the flat shape the admin UI already understands (http_code/context/
     * response_body), with the full per-account and per-row lists attached.
     */
    private function buildFailureResult(array $accountErrors, array $rowErrors, int $syncedCount, int $fetchedCount): array
    {
        $messages = [];
        $details = [];

        if (!empty($accountErrors)) {
            $first = $accountErrors[0]['error'];
            $httpCode = (int) ($first['http_code'] ?? 0);
            $context = (string) ($first['context'] ?? '');

            if ($httpCode > 0) {
                $apiMessage = 'Volání iDoklad API selhalo (HTTP ' . $httpCode . ')';
            } elseif ($context === 'input validation') {
                // response_body is only safe to surface directly for contexts where
                // we craft the Czech message ourselves.
                $apiMessage = $first['response_body'] ?? 'Chyba při synchronizaci iDokladu';
            } else {
                $apiMessage = 'Chyba při synchronizaci iDokladu';
            }

            if (count($accountErrors) > 1) {
                $apiMessage .= sprintf(' (chyba u %d z %d účtů)', count($accountErrors), count($this->accounts));
            } else {
                $apiMessage .= sprintf(' (účet %s)', $accountErrors[0]['account']);
            }

            $messages[] = $apiMessage;
            $details = array_merge($first, ['account_errors' => $accountErrors]);
        }

        if (!empty($rowErrors)) {
            $messages[] = sprintf(
                'Synchronizováno %d z %d faktur; %d selhalo při zápisu do DB',
                $syncedCount,
                $fetchedCount,
                count($rowErrors)
            );
            $details['row_errors'] = $rowErrors;
            $details['context'] ??= 'DB upsert';
        }

        return [
            'success' => false,
            'message' => implode('; ', $messages),
            'synced' => $syncedCount,
            'error_details' => $details,
        ];
    }

    public function syncAllEnabledCompanies(): array
    {
        if (!$this->isConfigured()) {
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
            'account_count' => count($this->accounts),
            'companies' => $results,
            'recent_invoices' => $this->invoiceRepo->findRecentDates(5),
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
        $this->lastPdfError = null;
        $this->lastPdfClient = null;

        // Verify user owns the invoice
        if (!$this->invoiceRepo->userOwnsInvoice($userId, $invoiceDbId)) {
            return null;
        }

        $invoice = $this->invoiceRepo->findById($invoiceDbId);

        if ($invoice === null) {
            return null;
        }

        $account = $this->resolveInvoiceAccount($invoice);

        if ($account === null) {
            $accountKey = (string) ($invoice['idoklad_account'] ?? '');
            $this->lastPdfError = [
                'context' => 'account config',
                'method' => 'GET',
                'url' => '',
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => sprintf(
                    'iDoklad účet "%s" pro tuto fakturu není nakonfigurován.',
                    $accountKey
                ),
                'timestamp' => date('c'),
            ];
            return null;
        }

        $client = $this->clientFor($account);
        $this->lastPdfClient = $client;

        $idokladId = (int) $invoice['idoklad_id'];

        return $client->getInvoicePdf($idokladId);
    }

    /**
     * The account that issued the invoice. Legacy rows (or empty keys) fall back
     * to the primary configured account so single-account deployments keep working.
     */
    private function resolveInvoiceAccount(array $invoice): ?IDokladAccount
    {
        $accountKey = (string) ($invoice['idoklad_account'] ?? '');

        if ($accountKey === '' || $accountKey === IDokladAccountRegistry::LEGACY_KEY) {
            return $this->accounts[0] ?? null;
        }

        foreach ($this->accounts as $account) {
            if ($account->key === $accountKey) {
                return $account;
            }
        }

        return null;
    }

    public function getLastPdfError(): ?array
    {
        if ($this->lastPdfError !== null) {
            return $this->lastPdfError;
        }

        return $this->lastPdfClient?->getLastError();
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
