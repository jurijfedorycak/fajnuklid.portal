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

        // The dashboard only surfaces the team size (Tým stat card) — worker
        // profiles live on the Personál page, so no names/photos are shipped.
        $personnelCount = 0;
        if ($activeClientId !== null) {
            $clientEmployees = $this->clientEmployeeRepo->findByClientId($activeClientId);
            foreach ($clientEmployees as $ce) {
                if (!empty($ce['show_in_portal'])) {
                    $personnelCount++;
                }
            }
        }

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
        // `{date, status}` contract the dashboard FE expects.
        $currentYear = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        [$prevYear, $prevMonth] = self::previousYearMonth($today);

        $clientForCleanings = $this->clientRepo->findByUserId($userId);
        $currentResult = null;
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

        // "Poslední úklid" hero chip. The two fetched months usually contain a
        // completed day; when they don't (client paused over the summer, brand
        // new FreshQR link), walk back a few more months so the chip doesn't
        // vanish for clients who demonstrably have a cleaning history. The
        // lookback is bounded and skipped entirely for demo clients (synthetic
        // schedule always has recent days) and during FreshQR outages (the
        // extra calls would be doomed anyway).
        $lastCleaningDate = self::findLastCompletedCleaningDate($cleaningDays);
        if (
            $lastCleaningDate === null
            && $currentResult !== null
            && ($currentResult['active'] ?? false)
            && ($currentResult['error'] ?? null) === null
            // Brand-new clients (no billing history) have no cleaning history
            // to find — the FE shows the onboarding hero without the chip
            // anyway, so don't pay 4 futile FreshQR calls on every one of
            // their dashboard requests. The range-scoped totals can be 0 for
            // an established client viewing a quiet period, so fall back to an
            // all-time check before concluding "brand new".
            && (
                $invoiceTotals['all'] > 0
                || $contract['hasPdf']
                || $this->invoiceRepo->getTotalsForUser($userId, $activeIco, '1970-01-01', '2999-12-31')['all'] > 0
            )
        ) {
            $companiesForLookback = $currentResult['companies'] ?? [];
            $cursor = new DateTime(sprintf('%04d-%02d-01', $prevYear, $prevMonth));
            for ($i = 0; $i < 4 && $lastCleaningDate === null && $companiesForLookback !== []; $i++) {
                [$y, $m] = self::previousYearMonth($cursor);
                $cursor = new DateTime(sprintf('%04d-%02d-01', $y, $m));
                $monthResult = $this->freshqr->getCleaningDaysForCompanies($companiesForLookback, $y, $m);
                if (($monthResult['error'] ?? null) !== null) {
                    continue;
                }
                $lastCleaningDate = self::findLastCompletedCleaningDate(
                    self::reshapeCleaningDaysForDashboard($monthResult['cleaningDays'] ?? [])
                );
            }
        }

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

        // The greeting is now per login account, so it comes straight off the
        // authenticated user loaded by the auth middleware — no client lookup needed.
        $userGreeting = isset($user['greeting']) ? trim((string) $user['greeting']) : '';
        $userGreeting = $userGreeting !== '' ? $userGreeting : null;

        $currentUser = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'displayName' => $activeCompany['name'] ?? $user['email'],
            'greeting' => $userGreeting,
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
            'lastCleaningDate' => $lastCleaningDate,
            'ongoingCleaning' => $ongoingCleaning,
            'reviewPrompt' => $reviewPromptPayload,
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
     *   { date, status: 'done' | 'ongoing' }
     *
     * Status is 'ongoing' iff the day's aggregated `ongoing` flag is set;
     * otherwise 'done' (a record exists for this day, so a cleaning happened).
     * We don't emit 'scheduled' here — FreshQR has no concept of a future plan,
     * and the demo service intentionally drops future dates so the dashboard
     * matches the "past + today only" feel of the Docházka calendar.
     *
     * SECURITY NOTE: the output is intentionally a narrow date/status tuple that
     * carries no scan times. If you add fields sourced from per-cleaning data,
     * route the cleaningDays input through AttendanceController's rounding-rule
     * redaction first — otherwise raw scan times would leak to clients whose
     * IČO has rounding rules configured.
     *
     * @param array<int,array<string,mixed>> $cleaningDays
     * @return list<array{date:string,status:string}>
     */
    private static function reshapeCleaningDaysForDashboard(array $cleaningDays): array
    {
        $out = [];
        foreach ($cleaningDays as $day) {
            $date = $day['date'] ?? null;
            if (!is_string($date) || $date === '') {
                continue;
            }
            $out[] = [
                'date' => $date,
                'status' => !empty($day['ongoing']) ? 'ongoing' : 'done',
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
     * The most recent completed cleaning date from the reshaped dashboard
     * cleaningDays, or null when none exists. An 'ongoing' day is not a
     * completed cleaning (it becomes one once it closes); a 'done' day dated
     * today counts. The merged multi-month input is not guaranteed sorted, so
     * this computes the maximum rather than taking the last element. Inherits
     * the reshape's SECURITY NOTE for free — the input carries no scan times.
     *
     * @param list<array{date:string,status:string}> $reshapedCleaningDays
     */
    private static function findLastCompletedCleaningDate(array $reshapedCleaningDays): ?string
    {
        $last = null;
        foreach ($reshapedCleaningDays as $day) {
            if (($day['status'] ?? null) !== 'done') {
                continue;
            }
            $date = $day['date'] ?? null;
            if (!is_string($date) || $date === '') {
                continue;
            }
            if ($last === null || $date > $last) {
                $last = $date;
            }
        }
        return $last;
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
