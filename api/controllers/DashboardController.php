<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ClientEmployeeRepository;
use App\Repositories\InvoiceRepository;
use DateTime;

class DashboardController extends Controller
{
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private ClientEmployeeRepository $clientEmployeeRepo;
    private InvoiceRepository $invoiceRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->clientEmployeeRepo = new ClientEmployeeRepository();
        $this->invoiceRepo = new InvoiceRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];

        // Get user's companies (drives the IČO switcher)
        $companies = $this->companyRepo->findByUserId($userId);

        // Resolve active company from ?ico= query (validate against user's companies);
        // fall back to the first company.
        $requestedIco = $request->query('ico');
        $activeCompany = null;
        if ($requestedIco !== null && $requestedIco !== '') {
            foreach ($companies as $company) {
                if ((string) $company['registration_number'] === (string) $requestedIco) {
                    $activeCompany = $company;
                    break;
                }
            }
        }
        if ($activeCompany === null && !empty($companies)) {
            $activeCompany = $companies[0];
        }
        $activeIco = $activeCompany['registration_number'] ?? null;
        $activeCompanyId = isset($activeCompany['id']) ? (int) $activeCompany['id'] : null;
        $activeClientId = isset($activeCompany['client_id']) ? (int) $activeCompany['client_id'] : null;

        // Resolve date range — default to YTD (1.1.{currentYear} → today).
        $today = new DateTime('today');
        $defaultFrom = (new DateTime('first day of January ' . $today->format('Y')))->format('Y-m-d');
        $defaultTo = $today->format('Y-m-d');
        $from = $this->normalizeDate($request->query('from'), $defaultFrom);
        $to = $this->normalizeDate($request->query('to'), $defaultTo);
        // Swap if reversed
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // Locations for the active company (used for "Vaše místo" subtitle)
        $allLocations = $this->locationRepo->findByUserId($userId);
        $activeLocations = array_values(array_filter($allLocations, function ($l) use ($activeCompanyId) {
            return $activeCompanyId !== null && (int) $l['company_id'] === $activeCompanyId;
        }));
        $primaryLocationName = $activeLocations[0]['name'] ?? ($activeLocations[0]['company_name'] ?? '');

        // Personnel preview for the active company (FE paginates).
        $personnelList = [];
        if ($activeClientId !== null) {
            $clientEmployees = $this->clientEmployeeRepo->findByClientId($activeClientId);
            foreach ($clientEmployees as $ce) {
                if (empty($ce['show_in_portal'])) {
                    continue;
                }
                $showName = !empty($ce['show_name']);
                $first = $showName ? trim((string) ($ce['first_name'] ?? '')) : '';
                $last = $showName ? trim((string) ($ce['last_name'] ?? '')) : '';
                $fullName = trim($first . ' ' . $last);
                if ($fullName === '') {
                    $fullName = 'Pracovník';
                }
                $personnelList[] = [
                    'id' => (int) $ce['employee_id'],
                    'name' => $fullName,
                    'role' => !empty($ce['show_role']) ? ($ce['position'] ?? '') : '',
                    'photoUrl' => !empty($ce['show_photo']) ? ($ce['photo_url'] ?? null) : null,
                ];
            }
        }
        $personnelCount = count($personnelList);

        // Contract info for the active company
        $contract = [
            'hasPdf' => false,
            'contractsEnabled' => false,
            'startDate' => null,
            'endDate' => null,
        ];
        if ($activeCompany !== null) {
            $contract = [
                'hasPdf' => !empty($activeCompany['contract_pdf_path']),
                'contractsEnabled' => true,
                'startDate' => $activeCompany['contract_start_date'] ?? null,
                'endDate' => $activeCompany['contract_end_date'] ?? null,
            ];
        }

        // Invoice aggregates + recent + next due
        $invoiceTotals = $this->invoiceRepo->getTotalsForUser($userId, $activeIco, $from, $to);
        $recentRows = $this->invoiceRepo->findRecentForUser($userId, $activeIco, $from, $to, 5);
        $recentInvoices = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'documentNumber' => $row['document_number'],
                'dueDate' => $row['date_due'],
                'amount' => (float) $row['total_amount'],
                'currency' => $row['currency_code'] ?? 'CZK',
                'status' => $row['payment_status'],
            ];
        }, $recentRows);

        $nextDueRow = $this->invoiceRepo->findNextDueForUser($userId, $activeIco);
        $nextDue = null;
        if ($nextDueRow !== null) {
            $dueDate = new DateTime($nextDueRow['date_due']);
            $diff = (int) $today->diff($dueDate)->format('%r%a');
            $nextDue = [
                'id' => (int) $nextDueRow['id'],
                'documentNumber' => $nextDueRow['document_number'],
                'dueDate' => $nextDueRow['date_due'],
                'daysRelative' => $diff,
                'amount' => (float) $nextDueRow['total_amount'],
                'currency' => $nextDueRow['currency_code'] ?? 'CZK',
            ];
        }

        // TODO: Fetch cleaning visits from external API. Until then, return empty.
        // Each cell must include "status" so the FE renders done/ongoing/scheduled correctly.
        $cleaningDays = [];

        // Build current user info
        $currentUser = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'displayName' => $activeCompany['name'] ?? $user['email'],
            'activeIco' => $activeIco,
            'clientId' => $activeClientId,
        ];

        // Switcher list — only the fields the FE needs.
        $companiesPayload = array_map(function ($c) {
            return [
                'id' => (int) $c['id'],
                'name' => $c['name'],
                'ico' => $c['registration_number'],
            ];
        }, $companies);

        Response::success([
            'currentUser' => $currentUser,
            'companies' => $companiesPayload,
            'activeIco' => $activeIco,
            'dateRange' => [
                'from' => $from,
                'to' => $to,
            ],
            'overview' => [
                'invoices' => [
                    'total' => $invoiceTotals['all'],
                    'paidCount' => $invoiceTotals['paid'],
                    'unpaidCount' => $invoiceTotals['unpaid'],
                    'overdueCount' => $invoiceTotals['overdue'],
                    'nextDue' => $nextDue,
                ],
                'personnel' => [
                    'count' => $personnelCount,
                    'locationName' => $primaryLocationName,
                ],
                'contract' => $contract,
            ],
            'cleaningDays' => $cleaningDays,
            'personnelList' => $personnelList,
            'recentInvoices' => $recentInvoices,
            'locations' => array_map(function ($l) {
                return [
                    'id' => (int) $l['id'],
                    'name' => $l['name'],
                    'companyName' => $l['company_name'] ?? null,
                ];
            }, $activeLocations),
        ]);
    }

    /**
     * Validate a YYYY-MM-DD date string. Returns the fallback if input is invalid.
     */
    private function normalizeDate(mixed $value, string $fallback): string
    {
        if (!is_string($value) || $value === '') {
            return $fallback;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if ($dt === false) {
            return $fallback;
        }
        // Reject things like "2026-13-40" that pass createFromFormat with overflow.
        if ($dt->format('Y-m-d') !== $value) {
            return $fallback;
        }
        return $value;
    }
}
