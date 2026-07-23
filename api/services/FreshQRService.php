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
 *        a. The record carries no `last_scan_time`. A null/empty value is the
 *           only "still on-site" signal either source emits — attendance-raw
 *           keeps TimeTo null until the scan-out, and the materialized report
 *           cache stores closed pairs only. Any recorded last_scan_time means
 *           the cleaner scanned out here, INCLUDING one equal to
 *           first_scan_time: that is a zero-length pair left by an accidental
 *           double-scan, not an open visit.
 *        b. The entry is still live: its date is today, OR (the overnight case)
 *           the scan-in was within MAX_ENTRY_MINUTES of now. An open scan older
 *           than that is a forgotten scan-out, not active work.
 *      Per-cleaning `ongoing` is surfaced in detailed mode so the FE never has
 *      to infer it from a null endTime — that signal is unreliable because
 *      single-scan past-day records also have null endTime.
 *   5. A cleaning that crosses midnight (finishes after 00:00) is a genuine
 *      overnight visit, not an anomaly: it's anchored to the day it STARTED, and
 *      its endTime reads lexically earlier than its startTime (e.g. 23:30→00:15)
 *      — the FE derives the "+1 day" marker from that, so no flag is stored.
 *      Duration comes from the per-entry `duration_minutes` (correct across
 *      midnight), never from the times. Only the per-entry duration cap
 *      (FreshQRClient::MAX_ENTRY_MINUTES) filters out forgotten scan-outs — the
 *      calendar-day boundary no longer does.
 */
class FreshQRService
{
    /**
     * The business calendar timezone. Pinned here (not just via index.php's
     * runtime default) so the "ongoing" boundary and the overnight active window
     * stay correct even from a CLI script or cron task that forgot to set it.
     */
    private const TIMEZONE = 'Europe/Prague';

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
     *           'endTime'          => 'HH:mm' | null,   // null when no scan-out recorded (still on-site)
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
        $now = self::now();
        $today = $now->format('Y-m-d');

        $records = $this->fetchRecordsForMonth($modeByIco, $year, $month, $today, $now);
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

        $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today, $now);

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

        $now = self::now();
        $today = $now->format('Y-m-d');

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
            $records = self::dropStalePastOpenScans($records, $today, $now);
            $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today, $now);
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

        $cleaningDays = $this->assembleCleaningDays($records, $companies, $modeByIco, $today, $now);

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
    private function fetchRecordsForMonth(
        array $modeByIco,
        int $year,
        int $month,
        string $today,
        \DateTimeImmutable $now
    ): ?array {
        if (in_array('detailed', $modeByIco, true)) {
            $from = new \DateTimeImmutable(
                sprintf('%04d-%02d-01', $year, $month),
                new \DateTimeZone(self::TIMEZONE)
            );
            $to = $from->modify('last day of this month');
            $records = $this->client->getAttendanceRawForRange($from, $to);
            return $records === null ? null : self::dropStalePastOpenScans($records, $today, $now);
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
     * Drop records that are still open (no scan-out) on a *past* day, EXCEPT an
     * overnight cleaning that's genuinely still running.
     *
     * attendance-raw carries every scan pair including unfinished ones; on a past
     * day an unfinished pair is normally a forgotten scan-out. The materialized-
     * report path never surfaced those (its cache excludes null scan-outs, re-adding
     * only today's live "Probíhá" scans), so filtering them keeps historical
     * calendars and visit counts stable across the source switch.
     *
     * The one past-day open scan we keep is a cleaning that started late yesterday
     * and is still in progress now (scan-in within MAX_ENTRY_MINUTES of $now) — that
     * is a live overnight visit, not a forgotten scan-out, and it belongs on its
     * start day flagged ongoing. Today's open scans are always kept.
     *
     * @param array<array<string,mixed>> $records
     * @return array<array<string,mixed>>
     */
    private static function dropStalePastOpenScans(
        array $records,
        string $today,
        \DateTimeImmutable $now
    ): array {
        return array_values(array_filter($records, static function ($record) use ($today, $now) {
            $isOpen = ($record['last_scan_time'] ?? null) === null;
            $date = is_string($record['date'] ?? null) ? $record['date'] : '';
            if (!$isOpen || $date >= $today) {
                return true;
            }
            return self::scanInWithinActiveWindow($record, $now);
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
    private function assembleCleaningDays(
        array $records,
        array $companies,
        array $modeByIco,
        string $today,
        \DateTimeImmutable $now
    ): array {
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
            $roundingRulesByIco,
            $now
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
     * A record is flagged as "ongoing" when the cleaner hasn't scanned out at
     * THIS project (last_scan_time missing) AND the entry is still live —
     * either it's today, or the scan-in was within MAX_ENTRY_MINUTES of $now
     * (the overnight case: a cleaning that began late yesterday and is still
     * running past midnight). The portal trusts FreshQR's null TimeTo as the
     * source of truth: a forgotten scan-out at IČO A followed by a scan-in at
     * IČO B leaves A's record without an end-time, and A genuinely remains
     * "open" until the cleaner returns to close it.
     *
     * Every entry is anchored to its scan-in day; a cleaning that finished after
     * midnight stays on the day it started, with an endTime that reads earlier
     * than its startTime (the FE turns that into a "+1 day" marker). Duration
     * comes from the record's explicit `duration_minutes` (correct across
     * midnight) when present, never inferred from the times.
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
     * @param \DateTimeImmutable|null     $now                  current instant (Europe/Prague); enables the
     *                                                          overnight "still live" window. Null → date-only ongoing.
     * @return list<array{date:string,ongoing:bool,cleanings:list<array{employee:string,startTime:?string,endTime:?string,ico:string,rawMinutes:?int,roundedMinutes:?int,ongoing:bool}>,icos:list<string>}>
     */
    public static function buildCleaningDays(
        array $records,
        array $modeByIco,
        array $allowedPersonalIds,
        array $displayNamesByPersonalId,
        string $today,
        array $roundingRulesByIco = [],
        ?\DateTimeImmutable $now = null
    ): array {
        $byDate = [];

        foreach ($records as $record) {
            $date = $record['date'] ?? null;
            if (!is_string($date) || !self::isValidDate($date)) {
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

            // Ongoing = the cleaner hasn't scanned out AND the entry is still
            // "live": either it's today, or (the overnight case) the scan-in was
            // within one entry's max length of now. A record anchored to
            // yesterday that's still open at 00:30 is a cleaning in progress, not
            // a finished visit — but only until MAX_ENTRY_MINUTES have elapsed,
            // past which an open scan is a forgotten scan-out, not active work.
            $isOngoing = !self::isScannedOutAtThisProject($record)
                && ($date === $today || self::scanInWithinActiveWindow($record, $now));

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
                $rawMinutes = self::entryRawMinutes($record, $startTime, $endTime);
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
     * Billable duration for a detailed-mode cleaning, in whole minutes.
     *
     * A null $endTime means the cleaner hasn't scanned out (open / still on-site),
     * so the cleaning has no final duration yet — return null regardless of any
     * value FreshQR may have attached, keeping billed totals and the "Probíhá"
     * display in agreement (a running cleaning must never contribute minutes).
     *
     * Otherwise the attendance-raw source's explicit per-entry `duration_minutes`
     * (FreshQR's own TIMESTAMPDIFF, correct across midnight) is the source of
     * truth — the only value that survives an overnight boundary, since the
     * HH:mm-based fallback can't tell 23:30→00:15 apart from an inverted pair.
     * Records without the field (legacy/report shapes) fall back to the start→end
     * HH:mm difference. Non-positive durations return null.
     *
     * @param array<string,mixed> $record
     */
    private static function entryRawMinutes(array $record, ?string $startTime, ?string $endTime): ?int
    {
        if ($endTime === null) {
            return null;
        }
        if (array_key_exists('duration_minutes', $record)) {
            $duration = $record['duration_minutes'];
            return is_int($duration) && $duration > 0 ? $duration : null;
        }
        return self::computeDurationMinutes($startTime, $endTime);
    }

    /**
     * True iff the record's scan-in happened within one entry's max length of
     * $now (and not in the future). This is what keeps a late-night cleaning
     * "live" past midnight: its start day is yesterday, but as long as it's still
     * inside the active window it counts as ongoing rather than a stale forgotten
     * scan-out. Returns false when $now is absent (callers that don't supply it
     * fall back to date-only "is it today" semantics) or the scan-in is
     * unparseable.
     *
     * @param array<string,mixed> $record
     */
    private static function scanInWithinActiveWindow(array $record, ?\DateTimeImmutable $now): bool
    {
        if ($now === null) {
            return false;
        }
        $scanIn = self::scanInDateTime($record);
        if ($scanIn === null) {
            return false;
        }
        $elapsedMinutes = ($now->getTimestamp() - $scanIn->getTimestamp()) / 60;
        return $elapsedMinutes >= 0 && $elapsedMinutes <= FreshQRClient::MAX_ENTRY_MINUTES;
    }

    /**
     * Reconstruct the scan-in instant (Europe/Prague) from a record's `date` and
     * `first_scan_time`. Minute granularity is enough for the active-window check.
     * Returns null when either part is missing or malformed.
     *
     * @param array<string,mixed> $record
     */
    private static function scanInDateTime(array $record): ?\DateTimeImmutable
    {
        $date = is_string($record['date'] ?? null) ? $record['date'] : '';
        $hm = self::formatScanTimeToHm(
            is_string($record['first_scan_time'] ?? null) ? $record['first_scan_time'] : ''
        );
        if ($date === '' || $hm === null) {
            return null;
        }
        $scanIn = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $date . ' ' . $hm,
            new \DateTimeZone(self::TIMEZONE)
        );
        return $scanIn === false ? null : $scanIn;
    }

    /**
     * True iff the record carries a recorded last_scan_time alongside its
     * first_scan_time — i.e. the cleaner has scanned out at this project and
     * the cleaning is finished. Neither live source ever echoes the scan-in
     * into last_scan_time as an "on-site" placeholder (attendance-raw keeps
     * TimeTo null until the scan-out; the materialized report cache stores
     * closed pairs only), so a last_scan_time EQUAL to first_scan_time is a
     * real zero-length pair — the residue of an accidental double-scan — and
     * must read as closed. Treating it as "still on-site" once flagged the
     * whole follow-up day ongoing after an overnight cleaning's post-midnight
     * double-scan (in+out within the same minute, date === today all day).
     *
     * Returns false in every other case:
     *   - both missing → no scan data, treat as ongoing if it's today
     *   - first present, last missing → only arrival recorded, still on-site
     *   - last present, first missing → ambiguous FreshQR data (unseen in
     *                                   either source); default to "not
     *                                   scanned out" so we don't wrongly
     *                                   declare an active cleaner finished
     *
     * @param array<string,mixed> $record
     */
    private static function isScannedOutAtThisProject(array $record): bool
    {
        $first = is_string($record['first_scan_time'] ?? null) ? trim((string) $record['first_scan_time']) : '';
        $last = is_string($record['last_scan_time'] ?? null) ? trim((string) $record['last_scan_time']) : '';

        return $first !== '' && $last !== '';
    }

    /**
     * End-of-cleaning time for the Detailed-mode payload. Returns null when
     * the record is still open (no last_scan_time recorded — see
     * isScannedOutAtThisProject, the single "is the cleaning finished here?"
     * answer per record) or when the recorded value is unparseable, in which
     * case the entry stays closed but endTime is simply omitted rather than
     * rendering garbage.
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
     * Current instant in the business timezone. Drives the overnight "is this
     * cleaning still live" window (scanInWithinActiveWindow) — that needs a
     * wall-clock time, not just today's date, so it can tell a cleaning still
     * running at 00:30 apart from one that started yesterday morning and was
     * never closed. Callers derive today's date as `$now->format('Y-m-d')`.
     */
    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
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
     * Returns null for malformed inputs. Rolls past 24h by collapsing modulo
     * 24h so an overnight cleaning's rounded end-time (e.g. 23:30 + 120 min)
     * reads as a real wall-clock time (01:30); because it then reads earlier
     * than the start, the FE renders it with the "+1 day" marker.
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
