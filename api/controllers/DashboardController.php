<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ClientEmployeeRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\AppSettingRepository;
use App\Services\DemoAttendanceService;
use App\Services\FreshQRService;
use App\Services\R2StorageService;
use App\Services\ReviewPromptService;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;

class DashboardController extends Controller
{
    private ClientRepository $clientRepo;
    private CompanyRepository $companyRepo;
    private LocationRepository $locationRepo;
    private ClientEmployeeRepository $clientEmployeeRepo;
    private InvoiceRepository $invoiceRepo;
    private AppSettingRepository $appSettingRepo;
    private R2StorageService $storage;
    private FreshQRService $freshqr;
    private ReviewPromptService $reviewPrompt;

    public function __construct()
    {
        $this->clientRepo = new ClientRepository();
        $this->companyRepo = new CompanyRepository();
        $this->locationRepo = new LocationRepository();
        $this->clientEmployeeRepo = new ClientEmployeeRepository();
        $this->invoiceRepo = new InvoiceRepository();
        $this->appSettingRepo = new AppSettingRepository();
        $this->storage = new R2StorageService();
        $this->freshqr = new FreshQRService();
        $this->reviewPrompt = new ReviewPromptService();
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
                    'photoUrl' => !empty($ce['show_photo']) ? $this->storage->resolveProxyUrl($ce['photo_url'] ?? null) : null,
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

        // Cleaning days for the previous + current calendar month. The dashboard
        // "Úklidy" card renders two mini-calendars (previous and current month),
        // both fed from this single array, so we fetch both months and merge.
        // Demo clients see the synthetic schedule (same path AttendanceController
        // uses); real clients go through FreshQR. Both sources are month-scoped
        // and expose `ongoing` per day already; we re-shape into the
        // `{date, status, note}` contract the dashboard FE expects.
        $currentYear = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        [$prevYear, $prevMonth] = self::previousYearMonth($today);

        $clientForCleanings = $this->clientRepo->findByUserId($userId);
        if ($clientForCleanings !== null && (bool) $clientForCleanings['is_demo']) {
            // Pin the TZ here too — DemoAttendanceService compares against the
            // injected "today", so a UTC-running CLI/cron would otherwise emit
            // yesterday's calendar near Prague midnight.
            $demoToday = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
            $currentRawCleaningDays = DemoAttendanceService::buildCleaningDays(
                $currentYear,
                $currentMonth,
                $demoToday
            );
            $prevRawCleaningDays = DemoAttendanceService::buildCleaningDays(
                $prevYear,
                $prevMonth,
                $demoToday
            );
        } else {
            $currentResult = $this->freshqr->getCleaningDaysForUser($userId, $currentYear, $currentMonth);
            $currentRawCleaningDays = $currentResult['cleaningDays'] ?? [];
            $prevResult = $this->freshqr->getCleaningDaysForUser($userId, $prevYear, $prevMonth);
            $prevRawCleaningDays = $prevResult['cleaningDays'] ?? [];
        }

        // Merge both months for the calendar widget. The live "úklid probíhá"
        // banner is driven from the current month alone (below) — only today can
        // be in progress, so the previous month never contributes an ongoing.
        $rawCleaningDays = array_merge($prevRawCleaningDays, $currentRawCleaningDays);

        $cleaningDays = self::reshapeCleaningDaysForDashboard($rawCleaningDays);

        // Map IČO → company name so an in-progress cleaning can name its object.
        // Demo cleanings carry the synthetic IČO, which has no DB company row —
        // seed it explicitly so the live banner still labels the object.
        $icoToName = [];
        foreach ($companies as $c) {
            $ico = isset($c['registration_number']) ? trim((string) $c['registration_number']) : '';
            if ($ico !== '') {
                $icoToName[$ico] = (string) ($c['name'] ?? '');
            }
        }
        if ($clientForCleanings !== null && (bool) $clientForCleanings['is_demo']) {
            $icoToName[DemoAttendanceService::DEMO_ICO] = DemoAttendanceService::DEMO_COMPANY_NAME;
        }

        $ongoingCleaning = self::buildOngoingCleaning(
            $currentRawCleaningDays,
            $today->format('Y-m-d'),
            $icoToName
        );

        $clientGreeting = null;
        if ($activeClientId !== null) {
            $clientRow = $this->clientRepo->findById($activeClientId);
            if ($clientRow !== null) {
                $greeting = isset($clientRow['greeting']) ? trim((string) $clientRow['greeting']) : '';
                $clientGreeting = $greeting !== '' ? $greeting : null;
            }
        }

        $currentUser = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'displayName' => $activeCompany['name'] ?? $user['email'],
            'greeting' => $clientGreeting,
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

        // "Zanechat recenzi" block — reuse the client row already loaded for the
        // cleaning calendar so no extra query is needed. The Google link is a single
        // company-wide setting; the block stays hidden until it is configured.
        $googleReviewUrl = $this->appSettingRepo->get(AppSettingRepository::KEY_GOOGLE_REVIEW_URL);
        $reviewToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
        $showReviewPrompt = $clientForCleanings !== null
            && $this->reviewPrompt->shouldShow($clientForCleanings, $googleReviewUrl, $reviewToday);
        $reviewPromptPayload = [
            'show' => $showReviewPrompt,
            'googleUrl' => $showReviewPrompt ? $googleReviewUrl : null,
        ];

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
            'ongoingCleaning' => $ongoingCleaning,
            'reviewPrompt' => $reviewPromptPayload,
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
     * Collapse the FreshQR/Demo cleaningDays output into the simpler shape the
     * dashboard FE renders in the cleaning summary widget:
     *   { date, status: 'done' | 'ongoing', note: ?string }
     *
     * Status is 'ongoing' iff the day's aggregated `ongoing` flag is set;
     * otherwise 'done' (a record exists for this day, so a cleaning happened).
     * We don't emit 'scheduled' here — FreshQR has no concept of a future plan,
     * and the demo service intentionally drops future dates so the dashboard
     * matches the "past + today only" feel of the Docházka calendar.
     *
     * The note is the first cleaning's note (when present) so the day-cell
     * tooltip on the dashboard surfaces something useful without growing the
     * payload to a full cleanings list.
     *
     * SECURITY NOTE: if you add new fields here, route the cleaningDays input
     * through AttendanceController::stripRawTimesWhenRounded first. The
     * AttendanceController applies rounding-rule redaction; this controller
     * currently bypasses that because the output is intentionally a narrow
     * date/status/note tuple. Expanding the tuple would leak raw scan times
     * to clients whose IČO has rounding rules configured.
     *
     * @param array<int,array<string,mixed>> $cleaningDays
     * @return list<array{date:string,status:string,note:?string}>
     */
    private static function reshapeCleaningDaysForDashboard(array $cleaningDays): array
    {
        $out = [];
        foreach ($cleaningDays as $day) {
            $date = $day['date'] ?? null;
            if (!is_string($date) || $date === '') {
                continue;
            }
            $note = null;
            $cleanings = $day['cleanings'] ?? [];
            if (is_array($cleanings)) {
                foreach ($cleanings as $c) {
                    $candidate = $c['note'] ?? null;
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $note = trim($candidate);
                        break;
                    }
                }
            }
            $out[] = [
                'date' => $date,
                'status' => !empty($day['ongoing']) ? 'ongoing' : 'done',
                'note' => $note,
            ];
        }
        return $out;
    }

    /**
     * Build the "Úklid právě probíhá" summary for the dashboard hero from the
     * raw current-month cleaningDays. Returns null when nothing is in progress
     * right now; otherwise a compact tuple the FE renders as a live banner.
     *
     * Disclosure mirrors the Docházka calendar: only detailed-mode cleanings
     * contribute employee names and a startTime, and the start is surfaced only
     * when the IČO has no rounding rules — the same `ongoing + hasRoundingRules
     * → hide start` rule AttendanceController applies, so the displayed
     * "od HH:mm" can't contradict the billed time once the cleaning closes.
     *
     * A day flagged ongoing purely by a basic-mode IČO (empty cleanings[]) still
     * returns a detail-free tuple so the banner can show a generic "právě
     * probíhá" without leaking who/when.
     *
     * @param array<int,array<string,mixed>> $rawCleaningDays
     * @param array<string,string> $icoToName  ico => company name
     * @return array{objectName:?string,since:?string,employees:list<string>}|null
     */
    private static function buildOngoingCleaning(array $rawCleaningDays, string $today, array $icoToName): ?array
    {
        $todayDay = null;
        foreach ($rawCleaningDays as $day) {
            if (($day['date'] ?? null) === $today) {
                $todayDay = $day;
                break;
            }
        }
        if ($todayDay === null || empty($todayDay['ongoing'])) {
            return null;
        }

        $employees = [];
        $objectNames = [];
        $since = null;

        $cleanings = is_array($todayDay['cleanings'] ?? null) ? $todayDay['cleanings'] : [];
        foreach ($cleanings as $c) {
            if (empty($c['ongoing'])) {
                continue;
            }

            $employee = isset($c['employee']) ? trim((string) $c['employee']) : '';
            if ($employee !== '' && !in_array($employee, $employees, true)) {
                $employees[] = $employee;
            }

            $ico = isset($c['ico']) ? trim((string) $c['ico']) : '';
            if ($ico !== '' && isset($icoToName[$ico])) {
                $name = trim($icoToName[$ico]);
                if ($name !== '' && !in_array($name, $objectNames, true)) {
                    $objectNames[] = $name;
                }
            }

            // Same redaction as AttendanceController: hide the start when the
            // IČO has rounding rules so the displayed "od HH:mm" never drifts
            // once the cleaning ends and the rounded range is committed.
            if (!empty($c['hasRoundingRules'])) {
                continue;
            }
            $start = $c['startTime'] ?? null;
            if (is_string($start) && preg_match('/^\d{2}:\d{2}$/', $start) === 1) {
                if ($since === null || $start < $since) {
                    $since = $start;
                }
            }
        }

        return [
            'objectName' => $objectNames === [] ? null : implode(', ', $objectNames),
            'since' => $since,
            'employees' => $employees,
        ];
    }

    /**
     * The calendar month immediately before the given date, as [year, month].
     * Anchored on "first day of last month" so the current day-of-month can't
     * cause the classic `-1 month` day-overflow (e.g. 31 Mar → 3 Mar), and the
     * January → previous December year rollback is handled correctly.
     *
     * @return array{0:int,1:int} [year, month] with month in 1..12
     */
    private static function previousYearMonth(DateTime $date): array
    {
        $prev = (clone $date)->modify('first day of last month');
        return [(int) $prev->format('Y'), (int) $prev->format('n')];
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
