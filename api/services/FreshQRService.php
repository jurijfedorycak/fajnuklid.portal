<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\FreshQRClient;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyRoundingRuleRepository;
use App\Repositories\EmployeeRepository;

/**
 * Turns FreshQR project attendance reports into the day-by-day "cleaning
 * happened" view the client portal shows.
 *
 * Mapping rules agreed with the business:
 *   1. Each FreshQR project has the customer IČO as a substring of its name.
 *      A record is relevant for a portal user iff the project name contains
 *      an IČO of one of the companies the user has access to.
 *   2. Each FreshQR employee exposes personal_number. Only records where this
 *      value matches a personal_id of a portal employee are kept — this filters
 *      out stray/test users in FreshQR.
 *   3. Per-IČO disclosure level (`companies.freshqr_mode`):
 *        - 'off'      → IČO contributes no records at all (calendar treats it
 *                       as if the user did not have this IČO).
 *        - 'basic'    → client sees only the date + whether the cleaning is
 *                       happening right now (ongoing). Employee identities and
 *                       scan times never cross the wire for this IČO.
 *        - 'detailed' → in addition to the above, every cleaning that day
 *                       contributes a `cleanings[]` entry exposing the
 *                       worker's display name and start/end scan times.
 *      The `cleanings[]` array is always present in the output so the FE has a
 *      stable shape — empty when no detailed-mode IČO matched the day.
 *   4. A cleaning is "ongoing" when BOTH of these hold:
 *        a. The cleaning date equals today's date (in Europe/Prague — see
 *           index.php where the timezone is pinned).
 *        b. The record's `last_scan_time` is null/empty or equal to
 *           `first_scan_time` (FreshQR's "still on-site" sentinel). A
 *           different last_scan_time means the cleaner scanned out here.
 *      Per-cleaning `ongoing` is surfaced in detailed mode so the FE never has
 *      to infer it from a null endTime — that signal is unreliable because
 *      single-scan past-day records also have null endTime.
 */
class FreshQRService
{
    private FreshQRClient $client;
    private CompanyRepository $companyRepo;
    private EmployeeRepository $employeeRepo;
    private CompanyRoundingRuleRepository $roundingRuleRepo;

    public function __construct()
    {
        $this->client = new FreshQRClient();
        $this->companyRepo = new CompanyRepository();
        $this->employeeRepo = new EmployeeRepository();
        $this->roundingRuleRepo = new CompanyRoundingRuleRepository();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function getLastError(): ?array
    {
        return $this->client->getLastError();
    }

    /**
     * Build the cleaningDays list for a portal user's Docházka calendar.
     *
     * Returns [
     *   'active'           => bool,
     *   'cleaningDays'     => [
     *     [
     *       'date'      => 'YYYY-MM-DD',
     *       'ongoing'   => bool,
     *       'cleanings' => [
     *         [
     *           'employee'         => 'Anna N.',
     *           'startTime'        => 'HH:mm',          // null when scan time absent
     *           'endTime'          => 'HH:mm' | null,   // null when same as start (still on-site)
     *           'ico'              => '12345678',
     *           'rawMinutes'       => int | null,       // raw duration; null when uncomputable
     *           'roundedMinutes'   => int | null,       // null when no rules apply (or duration uncomputable)
     *           'roundedEndTime'   => 'HH:mm' | null,   // raw startTime + roundedMinutes (the controller swaps this in for client views)
     *           'hasRoundingRules' => bool,             // true iff the IČO has rules configured (controls the client-view redactions)
     *           'ongoing'          => bool,
     *         ],
     *         ...
     *       ],
     *       'icos'      => ['12345678', ...],           // distinct client IČOs cleaned this day (both modes; no times/employees)
     *     ],
     *     ...
     *   ],
     *   'companies'        => array<array<string,mixed>>,  // user's company rows (empty when not yet loaded)
     *   'error'            => string | null,
     * ]
     *
     * Companies are surfaced so callers (e.g. AttendanceController for the
     * hourly summary) can reuse the rows this service already loaded — avoids
     * an extra CompanyRepository::findByUserId() per request.
     *
     * When FreshQR isn't configured, the user has no IČOs, or no IČO is in a
     * non-off mode, the method degrades gracefully to active=false so the FE
     * renders the onboarding fallback rather than a broken calendar.
     */
    public function getCleaningDaysForUser(int $userId, int $year, int $month): array
    {
        if (!$this->client->isConfigured()) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => [], 'error' => null];
        }

        $companies = $this->companyRepo->findByUserId($userId);

        return $this->getCleaningDaysForCompanies($companies, $year, $month);
    }

    /**
     * Variant that takes a pre-loaded company list. Lets the admin "preview as
     * client" flow pass the IČOs of an arbitrary client without granting the
     * admin a company_users link to them — authorisation lives at the controller
     * boundary, this method just maps companies → calendar.
     *
     * @param array<array<string,mixed>> $companies
     */
    public function getCleaningDaysForCompanies(array $companies, int $year, int $month): array
    {
        if (!$this->client->isConfigured()) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => $companies, 'error' => null];
        }

        $modeByIco = self::buildModeByIcoMap($companies);

        if (empty($modeByIco)) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => $companies, 'error' => null];
        }

        $this->client->resetLastError();
        $today = self::today();

        $records = $this->fetchRecordsForMonth($modeByIco, $year, $month, $today);
        if ($records === null) {
            // Configured and the client has IČOs, but FreshQR is unreachable.
            // Keep the calendar active so the FE doesn't fall back to onboarding
            // UI; surface a generic error the FE can turn into a banner.
            return [
                'active' => true,
                'cleaningDays' => [],
                'companies' => $companies,
                'error' => 'Docházku se nepodařilo načíst. Zkuste to prosím později.',
            ];
        }

        $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today);

        return [
            'active' => true,
            'cleaningDays' => $cleaningDays,
            'companies' => $companies,
            'error' => null,
        ];
    }

    /**
     * Range variant of getCleaningDaysForCompanies used by the Docházka overview
     * (period switcher: day / week / month / quarter / year). FreshQR only
     * answers month-scoped report queries (year-only requests fail upstream
     * with HTTP 400), so the window is walked month by month — the client
     * fetches them in parallel — and the assembled cleaning days are filtered
     * down to [$from, $to] inclusive. Months entirely in the future are never
     * requested: FreshQR carries no future data, so each one is a saved
     * round-trip (a whole-year period would otherwise always cost 12).
     *
     * No DB caching: every call hits FreshQR live (product decision). A year
     * period therefore pulls a full year of the materialized report; acceptable
     * for now, and the natural place to add a nightly cache later.
     *
     * @param array<array<string,mixed>> $companies
     */
    public function getCleaningDaysForCompaniesRange(
        array $companies,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        if (!$this->client->isConfigured()) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => $companies, 'error' => null];
        }

        $modeByIco = self::buildModeByIcoMap($companies);
        if (empty($modeByIco)) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => $companies, 'error' => null];
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $this->client->resetLastError();

        $today = self::today();

        $filterToRange = static function (array $cleaningDays) use ($fromStr, $toStr): array {
            return array_values(array_filter($cleaningDays, static function ($day) use ($fromStr, $toStr) {
                $date = $day['date'] ?? '';
                return is_string($date) && $date >= $fromStr && $date <= $toStr;
            }));
        };

        // Detailed mode: per-visit scan pairs from attendance-raw across the whole
        // window (chunked internally to respect the endpoint's span cap). Repeat
        // visits split and each duration excludes the between-visit gap; today's
        // open scans arrive in the same payload, so no separate ongoing merge.
        // All-or-nothing: a partial range would silently under-count the totals.
        if (in_array('detailed', $modeByIco, true)) {
            $records = $this->client->getAttendanceRawForRange($from, $to);
            if ($records === null) {
                return [
                    'active' => true,
                    'cleaningDays' => [],
                    'companies' => $companies,
                    'error' => 'Přehled docházky se nepodařilo načíst. Zkuste to prosím později.',
                ];
            }
            $records = self::dropStalePastOpenScans($records, $today);
            $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today);
            return [
                'active' => true,
                'cleaningDays' => $filterToRange($cleaningDays),
                'error' => null,
                'companies' => $companies,
            ];
        }

        // Basic-only path: cheaper materialized per-month report; tolerates a
        // partial failure (one spanned month unreachable) by returning what it got.
        $records = [];
        $anyFetchFailed = false;
        $months = self::monthsInRange($from, $to, $today);
        if ($months !== []) {
            foreach ($this->client->getProjectReportsForMonths($months) as $monthRecords) {
                if ($monthRecords === null) {
                    $anyFetchFailed = true;
                    continue;
                }
                $records = array_merge($records, $monthRecords);
            }
        }

        // Total FreshQR outage across every spanned month — keep the surface active
        // (don't fall back to onboarding) but surface a banner-friendly error.
        if ($anyFetchFailed && $records === []) {
            return [
                'active' => true,
                'cleaningDays' => [],
                'companies' => $companies,
                'error' => 'Přehled docházky se nepodařilo načíst. Zkuste to prosím později.',
            ];
        }

        // Append live open scans only when the range includes today — the
        // materialized report never carries in-progress cleanings.
        if ($fromStr <= $today && $today <= $toStr) {
            $ongoingRecords = $this->client->getOngoingProjectReports();
            if (is_array($ongoingRecords) && $ongoingRecords !== []) {
                $records = array_merge($records, $ongoingRecords);
            }
        }

        $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today);

        return [
            'active' => true,
            'cleaningDays' => $filterToRange($cleaningDays),
            // A partial failure (one spanned month unreachable) still returns the
            // months we did get, but flag it so the FE can hint the data is incomplete.
            'error' => $anyFetchFailed ? 'Některá data se nepodařilo načíst, přehled může být neúplný.' : null,
            'companies' => $companies,
        ];
    }

    /**
     * [year, month] pairs spanned by [$from, $to], truncated at the current
     * month so future months are never requested.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function monthsInRange(\DateTimeImmutable $from, \DateTimeImmutable $to, string $today): array
    {
        // Compared as zero-padded 'Y-m' strings, not as instants — $today is a
        // Europe/Prague date while $from/$to may carry another timezone, and an
        // instant comparison across offsets could silently drop the current month.
        $endYm = min($to->format('Y-m'), substr($today, 0, 7));

        $cursor = $from->modify('first day of this month');
        $months = [];
        while ($cursor->format('Y-m') <= $endYm) {
            $months[] = [(int) $cursor->format('Y'), (int) $cursor->format('n')];
            $cursor = $cursor->modify('+1 month');
        }
        return $months;
    }

    /**
     * Fetch the raw records for a single month from the source appropriate to
     * the disclosure mix.
     *
     * Detailed mode needs per-visit scan pairs so same-object repeat visits split
     * and each duration excludes the between-visit gap — only attendance-raw
     * exposes individual pairs, and it already carries today's still-open scans,
     * so no separate ongoing merge is required. Basic-only setups keep the cheaper
     * materialized /v1/reports/projects path (which never exposes times, so the
     * collapse is invisible to them) and append live open scans for the current
     * month to drive the "Probíhá" indicator.
     *
     * Returns null on a transient FreshQR failure (surfaced as a banner upstream).
     *
     * @param array<string,string> $modeByIco
     * @return array<array<string,mixed>>|null
     */
    private function fetchRecordsForMonth(array $modeByIco, int $year, int $month, string $today): ?array
    {
        if (in_array('detailed', $modeByIco, true)) {
            $from = new \DateTimeImmutable(
                sprintf('%04d-%02d-01', $year, $month),
                new \DateTimeZone('Europe/Prague')
            );
            $to = $from->modify('last day of this month');
            $records = $this->client->getAttendanceRawForRange($from, $to);
            return $records === null ? null : self::dropStalePastOpenScans($records, $today);
        }

        $records = $this->client->getProjectReports($year, $month);
        if ($records === null) {
            return null;
        }
        if (self::yearMonthIsCurrent($year, $month, $today)) {
            $ongoingRecords = $this->client->getOngoingProjectReports();
            if (is_array($ongoingRecords) && $ongoingRecords !== []) {
                $records = array_merge($records, $ongoingRecords);
            }
        }
        return $records;
    }

    /**
     * Drop records that are still open (no scan-out) on a *past* day.
     *
     * attendance-raw carries every scan pair including unfinished ones; on a past
     * day an unfinished pair is a forgotten scan-out. The materialized-report path
     * never surfaced those (its cache excludes null scan-outs, re-adding only
     * today's live "Probíhá" scans), so filtering them keeps historical calendars
     * and visit counts stable across the source switch. Today's open scans are
     * kept — that's the ongoing indicator.
     *
     * @param array<array<string,mixed>> $records
     * @return array<array<string,mixed>>
     */
    private static function dropStalePastOpenScans(array $records, string $today): array
    {
        return array_values(array_filter($records, static function ($record) use ($today) {
            $isOpen = ($record['last_scan_time'] ?? null) === null;
            $date = is_string($record['date'] ?? null) ? $record['date'] : '';
            return !($isOpen && $date < $today);
        }));
    }

    /**
     * Turn raw FreshQR records into the cleaningDays list, loading the auxiliary
     * lookups (allow-list, display names, rounding rules) lazily. Shared by the
     * month-scoped and range-scoped entry points so both apply identical
     * disclosure and rounding rules.
     *
     * @param array<array<string,mixed>>  $records
     * @param array<array<string,mixed>>  $companies
     * @param array<string,string>        $modeByIco
     */
    private function assembleCleaningDays(array $records, array $companies, array $modeByIco, string $today): array
    {
        $allowedPersonalIds = array_fill_keys($this->employeeRepo->getAllPersonalIds(), true);

        // Look up display names only when at least one IČO is in detailed mode —
        // basic-only setups never expose employee identities, so the query is wasted.
        $hasDetailed = in_array('detailed', $modeByIco, true);
        $displayNames = $hasDetailed
            ? $this->employeeRepo->findDisplayNamesByPersonalIds(array_keys($allowedPersonalIds))
            : [];

        // Rounding rules: only worth loading when detailed mode is active for at
        // least one IČO — basic-mode payloads don't carry per-cleaning fields, so
        // there's nowhere to apply the rounded duration.
        $roundingRulesByIco = $hasDetailed
            ? self::buildRoundingRulesByIcoMap($companies, $this->roundingRuleRepo)
            : [];

        return self::buildCleaningDays(
            $records,
            $modeByIco,
            $allowedPersonalIds,
            $displayNames,
            $today,
            $roundingRulesByIco
        );
    }

    /**
     * True iff at least one of the given companies has FreshQR switched on
     * (mode 'basic' or 'detailed'). Drives whether the portal exposes the
     * attendance surfaces (Přehled docházky card + Docházka tab): clients
     * without an activated QR system on any IČO get those hidden entirely.
     *
     * Deliberately decoupled from FreshQR connectivity (isConfigured) — tab
     * visibility is a per-IČO configuration decision, not a runtime health
     * check, so a temporary FreshQR outage must not hide the tab for a client
     * who has it enabled.
     *
     * @param array<array<string,mixed>> $companies
     */
    public static function isAttendanceEnabledForCompanies(array $companies): bool
    {
        return self::buildModeByIcoMap($companies) !== [];
    }

    /**
     * Extract well-formed IČOs from a list of company rows. Anything that
     * isn't an all-digit string of a plausible length is dropped so the
     * substring matcher can't be tricked by empty / wildcard inputs.
     *
     * @param array<array<string,mixed>> $companies
     * @return list<string>
     */
    public static function extractIcos(array $companies): array
    {
        $icos = [];

        foreach ($companies as $c) {
            $ico = self::sanitiseIco($c['registration_number'] ?? null);
            if ($ico !== null) {
                $icos[] = $ico;
            }
        }

        return array_values(array_unique($icos));
    }

    /**
     * Map IČO → freshqr_mode for IČOs whose mode is not 'off'. Off-mode IČOs
     * are excluded so they never participate in matching downstream — the
     * client must see no record originating from an opted-out company.
     *
     * @param array<array<string,mixed>> $companies
     * @return array<string,string>
     */
    public static function buildModeByIcoMap(array $companies): array
    {
        $map = [];
        foreach ($companies as $c) {
            $ico = self::sanitiseIco($c['registration_number'] ?? null);
            if ($ico === null) {
                continue;
            }
            $mode = is_string($c['freshqr_mode'] ?? null) ? strtolower(trim($c['freshqr_mode'])) : 'off';
            if (!in_array($mode, ['basic', 'detailed'], true)) {
                continue;
            }
            // If two company rows share an IČO and disagree on mode (shouldn't
            // happen — registration_number is UK — but defensive), prefer the
            // most disclosing mode so the union of admin choices wins.
            if (isset($map[$ico]) && $map[$ico] === 'detailed') {
                continue;
            }
            $map[$ico] = $mode;
        }
        return $map;
    }

    /**
     * Collapse FreshQR per-employee records into one entry per date.
     *
     * A record is flagged as "ongoing" when (a) it's today AND (b) the cleaner
     * hasn't scanned out at THIS project — i.e. last_scan_time is missing or
     * equal to first_scan_time. The portal trusts FreshQR's null TimeTo as the
     * source of truth: a forgotten scan-out at IČO A followed by a scan-in at
     * IČO B leaves A's record without an end-time, and A genuinely remains
     * "open" until the cleaner returns to close it.
     *
     * Each output day carries a `cleanings[]` array — populated from records
     * whose IČO matched a 'detailed' mode entry, empty otherwise. Per-cleaning
     * entries carry their own `ongoing` flag and are sorted chronologically by
     * startTime (then by employee for stable ordering).
     *
     * Each day also carries an `icos[]` list — the distinct client-owned IČOs
     * cleaned that day across BOTH modes. It never carries times or employee
     * identities, so it stays within the basic-mode disclosure boundary while
     * letting the overview count per-object visits for basic IČOs.
     *
     * @param array<array<string,mixed>> $records              Raw data[] from /v1/reports/projects
     * @param array<string,string>       $modeByIco            ico => 'basic'|'detailed' (off-mode IČOs absent)
     * @param array<string,mixed>        $allowedPersonalIds   array_flip of portal personal_ids (keys = ids)
     * @param array<string,string>       $displayNamesByPersonalId  personal_id => 'Jméno P.'
     * @param string                     $today                YYYY-MM-DD, injected for testability
     * @param array<string,array<int,array<string,mixed>>> $roundingRulesByIco
     *                                                          ico => list of rule rows (empty list / missing ico = no rounding)
     * @return list<array{date:string,ongoing:bool,cleanings:list<array{employee:string,startTime:?string,endTime:?string,ico:string,rawMinutes:?int,roundedMinutes:?int,ongoing:bool}>,icos:list<string>}>
     */
    public static function buildCleaningDays(
        array $records,
        array $modeByIco,
        array $allowedPersonalIds,
        array $displayNamesByPersonalId,
        string $today,
        array $roundingRulesByIco = []
    ): array {
        $byDate = [];

        foreach ($records as $record) {
            $date = $record['date'] ?? null;
            if (!is_string($date) || !self::isValidDate($date)) {
                continue;
            }

            // Business invariant: every cleaning starts and ends on the same
            // calendar day — Fajnúklid doesn't run overnight cleanings, and the
            // ongoing/finished logic downstream relies on a single-day window.
            // If FreshQR ever ships an ISO-timestamp scan whose date portion
            // disagrees with the record's `date`, the record is anomalous
            // (typically a midnight-crossing artefact) — drop it instead of
            // showing the client a cleaning that appears to span two days.
            if (!self::recordIsSingleDay($record, $date)) {
                continue;
            }

            $projectName = (string) ($record['project']['name'] ?? '');
            $matchedIco = self::findMatchingIco($projectName, array_keys($modeByIco));
            if ($matchedIco === null) {
                continue;
            }

            $personalNumber = $record['employee']['personal_number'] ?? null;
            if ($personalNumber === null || $personalNumber === '') {
                continue;
            }
            if (!isset($allowedPersonalIds[(string) $personalNumber])) {
                continue;
            }

            $isOngoing = $date === $today
                && !self::isScannedOutAtThisProject($record);

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'ongoing' => $isOngoing,
                    'cleanings' => [],
                    'icos' => [],
                ];
            } elseif ($isOngoing) {
                // Another employee on the same day may still be active even if
                // this specific record was already overtaken; OR the flags.
                $byDate[$date]['ongoing'] = true;
            }

            // Track which of the client's own IČOs were cleaned this day —
            // regardless of mode. Basic-mode IČOs contribute no cleanings[] entry
            // (no times/employees ever leave the server for them), but the client
            // already sees on the calendar that a cleaning happened, and the IČO
            // is their own company, so the overview can safely count visits per
            // object for basic IČOs too. Times stay detailed-only downstream.
            if (!in_array($matchedIco, $byDate[$date]['icos'], true)) {
                $byDate[$date]['icos'][] = $matchedIco;
            }

            if (($modeByIco[$matchedIco] ?? null) === 'detailed') {
                $startTime = self::formatScanTimeToHm((string) ($record['first_scan_time'] ?? ''));
                $endTime = self::computeEndTime($record);
                $rawMinutes = self::computeDurationMinutes($startTime, $endTime);
                $rules = $roundingRulesByIco[$matchedIco] ?? [];
                $hasRoundingRules = $rules !== [];
                $roundedMinutes = ($rawMinutes !== null && $hasRoundingRules)
                    ? TimeRoundingService::roundDuration($rawMinutes, $rules)
                    : null;
                // Shifted display end-time: when rounding changes the duration,
                // the client-facing range must add up to the billed minutes or
                // the math reads wrong. Anchor on the raw start (the cleaner's
                // actual arrival is the audit-stable point) and re-derive end
                // as start + rounded duration. The controller decides whether
                // to swap this in for the raw endTime on a per-role basis.
                $roundedEndTime = ($startTime !== null && $roundedMinutes !== null)
                    ? self::shiftTimeByMinutes($startTime, $roundedMinutes)
                    : null;

                $byDate[$date]['cleanings'][] = [
                    'employee'         => $displayNamesByPersonalId[(string) $personalNumber] ?? (string) $personalNumber,
                    'startTime'        => $startTime,
                    'endTime'          => $endTime,
                    'ico'              => $matchedIco,
                    'rawMinutes'       => $rawMinutes,
                    'roundedMinutes'   => $roundedMinutes,
                    'roundedEndTime'   => $roundedEndTime,
                    'hasRoundingRules' => $hasRoundingRules,
                    'ongoing'          => $isOngoing,
                ];
            }
        }

        ksort($byDate);

        // Sort cleanings within each day chronologically, with employee name as a
        // stable tiebreaker for records that share a start time.
        foreach ($byDate as &$day) {
            usort($day['cleanings'], static function ($a, $b) {
                $cmp = strcmp((string) ($a['startTime'] ?? ''), (string) ($b['startTime'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp((string) ($a['employee'] ?? ''), (string) ($b['employee'] ?? ''));
            });
        }
        unset($day);

        return array_values($byDate);
    }

    /**
     * Verify that every available scan-time on the record falls on the same
     * calendar day as `record['date']`. Only ISO-style scan times carry a
     * date portion ("YYYY-MM-DDTHH:MM:SS…") — bare HH:MM:SS values have no
     * date to compare, so they implicitly pass (we trust the record's `date`).
     *
     * Returns true when no scan-time disagrees with $expectedDate.
     * Returns false when at least one scan-time's date portion is well-formed
     * and differs — surfaced via error_log so the admin can see when FreshQR
     * delivers cross-midnight records.
     *
     * @param array<string,mixed> $record
     */
    private static function recordIsSingleDay(array $record, string $expectedDate): bool
    {
        foreach (['first_scan_time', 'last_scan_time'] as $field) {
            $value = $record[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})T/', $value, $m)) {
                continue;
            }
            if ($m[1] !== $expectedDate) {
                error_log(sprintf(
                    'FreshQR record dropped: %s=%s disagrees with date=%s (project=%s, personal_number=%s)',
                    $field,
                    $value,
                    $expectedDate,
                    (string) ($record['project']['name'] ?? ''),
                    (string) ($record['employee']['personal_number'] ?? '')
                ));
                return false;
            }
        }
        return true;
    }

    /**
     * True iff the record carries BOTH a first_scan_time AND a last_scan_time
     * that differ — i.e. the cleaner has scanned out at this project and the
     * cleaning is finished. Returns false in every other case:
     *   - both missing → no scan data, treat as ongoing if it's today
     *   - first present, last missing → only arrival recorded, still on-site
     *   - last present, first missing → ambiguous FreshQR data; default to
     *                                   "not scanned out" so we don't wrongly
     *                                   declare an active cleaner finished
     *   - both present, equal         → single scan, treated as still on-site
     *                                   (matches computeEndTime semantics)
     *
     * @param array<string,mixed> $record
     */
    private static function isScannedOutAtThisProject(array $record): bool
    {
        $first = is_string($record['first_scan_time'] ?? null) ? trim((string) $record['first_scan_time']) : '';
        $last = is_string($record['last_scan_time'] ?? null) ? trim((string) $record['last_scan_time']) : '';

        if ($first === '' || $last === '') {
            return false;
        }
        // Normalise both to HH:MM:SS before comparing so a record that stores
        // HH:MM in one field and HH:MM:SS in the other isn't falsely flagged as
        // "scanned out at a different time".
        return self::normaliseScanTimeForCompare($first) !== self::normaliseScanTimeForCompare($last);
    }

    /**
     * Coerce a raw FreshQR scan time string into HH:MM:SS for lexical
     * comparison. Built on top of formatScanTimeToHm so both HH:MM[:SS] and
     * ISO 8601 forms collapse to the same shape. Returns '' for unparseable
     * input so the comparison treats it as "no time recorded".
     *
     * Used by isScannedOutAtThisProject and computeEndTime to compare
     * first_scan_time against last_scan_time uniformly regardless of which
     * timestamp shape FreshQR chose for either field on a given record.
     */
    private static function normaliseScanTimeForCompare(string $raw): string
    {
        $hm = self::formatScanTimeToHm($raw);
        return $hm === null ? '' : $hm . ':00';
    }

    /**
     * End-of-cleaning time for the Detailed-mode payload. Returns null when
     * last_scan_time is missing OR represents the same wall-clock moment as
     * first_scan_time — both mean "no second scan recorded yet, treat as still
     * on-site". Equality is checked through `normaliseScanTimeForCompare` so
     * sub-minute jitter (e.g. first='08:00:30', last='08:00:55') doesn't pull
     * the predicate out of sync with isScannedOutAtThisProject — there must be
     * exactly one "is the cleaning finished here?" answer per record or the FE
     * ends up with mixed ongoing/endTime states.
     *
     * @param array<string,mixed> $record
     */
    private static function computeEndTime(array $record): ?string
    {
        if (!self::isScannedOutAtThisProject($record)) {
            return null;
        }
        $last = (string) ($record['last_scan_time'] ?? '');
        return self::formatScanTimeToHm($last);
    }

    /**
     * Coerce a raw FreshQR scan time string into HH:mm. Accepts:
     *   - HH:MM:SS or HH:MM (truncated to first 5 chars)
     *   - YYYY-MM-DDTHH:MM:SS[Z|+ZZ] (the time portion lifted out)
     * Out-of-range values (hours > 23, minutes > 59) and unparseable input
     * return null — the FE gracefully omits the value rather than rendering
     * garbage like "25:99".
     */
    public static function formatScanTimeToHm(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
            return self::buildHmIfValid($m[1], $m[2]);
        }
        if (preg_match('/T(\d{2}):(\d{2})/', $raw, $m)) {
            return self::buildHmIfValid($m[1], $m[2]);
        }
        return null;
    }

    private static function buildHmIfValid(string $hh, string $mm): ?string
    {
        $h = (int) $hh;
        $m = (int) $mm;
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return $hh . ':' . $mm;
    }

    /**
     * Return the first IČO from $icos that the project name contains as an
     * isolated digit run. Same boundary guard as the legacy boolean version
     * (an 8-digit IČO 12345678 must NOT match inside a 9-digit string), but
     * returns the matched IČO so callers can look up its mode.
     *
     * @param list<string|int> $icos  Numeric-string keys from array_keys() get
     *                                coerced to int by PHP — accept both.
     */
    private static function findMatchingIco(string $projectName, array $icos): ?string
    {
        if ($projectName === '') {
            return null;
        }
        foreach ($icos as $ico) {
            $ico = (string) $ico;
            if ($ico === '') {
                continue;
            }
            $pattern = '/(?<!\d)' . preg_quote($ico, '/') . '(?!\d)/u';
            if (preg_match($pattern, $projectName) === 1) {
                return $ico;
            }
        }
        return null;
    }

    /**
     * True iff $date is a real YYYY-MM-DD calendar date. Rejects "2026-13-01"
     * and "2026-02-30" as well as junk like "not-a-date".
     */
    private static function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            return false;
        }
        return $parsed->format('Y-m-d') === $date;
    }

    /**
     * Validate and normalise a registration_number into a usable IČO string
     * (4–10 digits, all numeric, trimmed). Returns null when the input is
     * unusable so the substring matcher can never be tricked by empty values.
     */
    private static function sanitiseIco(mixed $raw): ?string
    {
        $ico = trim((string) ($raw ?? ''));
        if ($ico === '' || !ctype_digit($ico)) {
            return null;
        }
        $len = strlen($ico);
        if ($len < 4 || $len > 10) {
            return null;
        }
        return $ico;
    }

    /**
     * Today's date in Europe/Prague. Pinned explicitly here (not just via the
     * runtime default in index.php) so the FreshQR "ongoing today" boundary
     * stays correct even if a CLI script or cron task forgot to set the
     * timezone before constructing the service.
     */
    private static function today(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
    }

    /**
     * True iff the (year, month) the user is viewing is the current calendar
     * month in Europe/Prague. Used to gate the live attendance-raw call —
     * ongoing scans can only ever fall on today, so past/future months don't
     * benefit from the extra round-trip.
     */
    private static function yearMonthIsCurrent(int $year, int $month, string $today): bool
    {
        $parts = explode('-', $today);
        if (count($parts) < 2) {
            return false;
        }
        return $year === (int) $parts[0] && $month === (int) $parts[1];
    }

    /**
     * Build a map of IČO → rounding rule rows for the IČOs the user has access
     * to. Off-mode IČOs are intentionally skipped (they never reach the cleanings
     * branch in buildCleaningDays anyway). Empty rule lists are omitted so the
     * downstream check is a simple `$rules !== []`.
     *
     * @param array<array<string,mixed>> $companies
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function buildRoundingRulesByIcoMap(
        array $companies,
        CompanyRoundingRuleRepository $roundingRuleRepo
    ): array {
        $companyIds = [];
        $idToIco = [];
        foreach ($companies as $c) {
            $companyId = isset($c['id']) ? (int) $c['id'] : 0;
            $ico = self::sanitiseIco($c['registration_number'] ?? null);
            if ($companyId <= 0 || $ico === null) {
                continue;
            }
            $companyIds[] = $companyId;
            $idToIco[$companyId] = $ico;
        }

        if ($companyIds === []) {
            return [];
        }

        $rulesByCompany = $roundingRuleRepo->findByCompanyIds($companyIds);

        $rulesByIco = [];
        foreach ($rulesByCompany as $companyId => $rules) {
            $ico = $idToIco[$companyId] ?? null;
            if ($ico === null || $rules === []) {
                continue;
            }
            $rulesByIco[$ico] = $rules;
        }
        return $rulesByIco;
    }

    /**
     * Compute visit duration in whole minutes from HH:mm start and end strings.
     * Returns null when either side is missing or the difference is non-positive
     * (typically a data error: start later than end). The caller treats null as
     * "no duration available, leave rounding empty" so the FE renders the
     * "Probíhá" affordance instead of a fake billable value.
     */
    public static function computeDurationMinutes(?string $startTime, ?string $endTime): ?int
    {
        if ($startTime === null || $endTime === null) {
            return null;
        }
        $start = self::parseHmToMinutes($startTime);
        $end = self::parseHmToMinutes($endTime);
        if ($start === null || $end === null) {
            return null;
        }
        $diff = $end - $start;
        if ($diff <= 0) {
            return null;
        }
        return $diff;
    }

    /**
     * Parse a HH:mm string into minutes since midnight. Returns null on any
     * malformed input — buildCleaningDays only feeds in strings produced by
     * formatScanTimeToHm, but the guard keeps the helper safe to reuse.
     */
    private static function parseHmToMinutes(string $hm): ?int
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $hm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }

    /**
     * Add a duration in minutes to a HH:mm anchor and format the result back
     * as HH:mm. Used to derive a display-only "rounded end time" that lines
     * up with the billed duration, anchored on the raw scan-in time so the
     * arrival point stays stable.
     *
     * Returns null for malformed inputs. Rolls into the next calendar day
     * by collapsing to modulo 24h — Fajnúklid doesn't bill overnight visits
     * (cross-midnight records get dropped upstream by recordIsSingleDay) so
     * a same-day display value is the correct expectation here.
     */
    public static function shiftTimeByMinutes(string $hm, int $minutes): ?string
    {
        $base = self::parseHmToMinutes($hm);
        if ($base === null) {
            return null;
        }
        $total = ($base + $minutes) % (24 * 60);
        if ($total < 0) {
            $total += 24 * 60;
        }
        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
