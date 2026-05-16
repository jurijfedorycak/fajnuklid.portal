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
 *   4. A cleaning is "ongoing" only if it is on today's date AND the employee
 *      has not moved on to another project since. Once the employee scans in
 *      at a different project, the earlier one is finished — so two clients
 *      never simultaneously see the same employee as "cleaning now".
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
     *           'employee'      => 'Anna N.',
     *           'startTime'     => 'HH:mm',          // null when scan time absent
     *           'endTime'       => 'HH:mm' | null,   // null when same as start (still on-site)
     *           'note'          => string | null,    // FreshQR doesn't return notes today; surfaced as-is when it does
     *           'ico'           => '12345678',
     *           'rawMinutes'    => int | null,       // null when start/end times can't form a duration
     *           'roundedMinutes'=> int | null,       // null when no rules defined or duration uncomputable
     *         ],
     *         ...
     *       ],
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
        $modeByIco = self::buildModeByIcoMap($companies);

        if (empty($modeByIco)) {
            return ['active' => false, 'cleaningDays' => [], 'companies' => $companies, 'error' => null];
        }

        $this->client->resetLastError();
        $records = $this->client->getProjectReports($year, $month);

        if ($records === null) {
            // Configured and user has IČOs, but FreshQR is unreachable. Keep the
            // calendar active so the FE doesn't fall back to onboarding UI; surface
            // a generic error the FE can turn into a banner.
            return [
                'active' => true,
                'cleaningDays' => [],
                'companies' => $companies,
                'error' => 'Docházku se nepodařilo načíst. Zkuste to prosím později.',
            ];
        }

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

        $cleaningDays = self::buildCleaningDays(
            $records,
            $modeByIco,
            $allowedPersonalIds,
            $displayNames,
            self::today(),
            $roundingRulesByIco
        );

        return [
            'active' => true,
            'cleaningDays' => $cleaningDays,
            'companies' => $companies,
            'error' => null,
        ];
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
     * Two passes: first we find each employee's latest scan-time per day across
     * ALL their records (even ones on non-matching projects), so we know
     * whether they moved on to somewhere else. Then we build the output and
     * flag a record as "ongoing" only if it's today AND it holds that latest
     * scan — otherwise the employee has already moved to another project and
     * this one is finished.
     *
     * Each output day carries a `cleanings[]` array — populated from records
     * whose IČO matched a 'detailed' mode entry, empty otherwise. Per-cleaning
     * entries are sorted chronologically by startTime (then by employee for
     * stable ordering).
     *
     * @param array<array<string,mixed>> $records              Raw data[] from /v1/reports/projects
     * @param array<string,string>       $modeByIco            ico => 'basic'|'detailed' (off-mode IČOs absent)
     * @param array<string,mixed>        $allowedPersonalIds   array_flip of portal personal_ids (keys = ids)
     * @param array<string,string>       $displayNamesByPersonalId  personal_id => 'Jméno P.'
     * @param string                     $today                YYYY-MM-DD, injected for testability
     * @param array<string,array<int,array<string,mixed>>> $roundingRulesByIco
     *                                                          ico => list of rule rows (empty list / missing ico = no rounding)
     * @return list<array{date:string,ongoing:bool,cleanings:list<array{employee:string,startTime:?string,endTime:?string,note:?string,ico:string,rawMinutes:?int,roundedMinutes:?int}>}>
     */
    public static function buildCleaningDays(
        array $records,
        array $modeByIco,
        array $allowedPersonalIds,
        array $displayNamesByPersonalId,
        string $today,
        array $roundingRulesByIco = []
    ): array {
        $latestScanPerEmployeeDay = self::indexLatestScanPerEmployeeDay($records);

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

            $isOngoing = $date === $today && self::isLatestScanForEmployeeDay(
                $record,
                (string) $personalNumber,
                $date,
                $latestScanPerEmployeeDay
            );

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'ongoing' => $isOngoing,
                    'cleanings' => [],
                ];
            } elseif ($isOngoing) {
                // Another employee on the same day may still be active even if
                // this specific record was already overtaken; OR the flags.
                $byDate[$date]['ongoing'] = true;
            }

            if (($modeByIco[$matchedIco] ?? null) === 'detailed') {
                $startTime = self::formatScanTimeToHm((string) ($record['first_scan_time'] ?? ''));
                $endTime = self::computeEndTime($record);
                $rawMinutes = self::computeDurationMinutes($startTime, $endTime);
                $rules = $roundingRulesByIco[$matchedIco] ?? [];
                $roundedMinutes = ($rawMinutes !== null && $rules !== [])
                    ? TimeRoundingService::roundDuration($rawMinutes, $rules)
                    : null;

                $byDate[$date]['cleanings'][] = [
                    'employee'       => $displayNamesByPersonalId[(string) $personalNumber] ?? (string) $personalNumber,
                    'startTime'      => $startTime,
                    'endTime'        => $endTime,
                    'note'           => self::extractNote($record),
                    'ico'            => $matchedIco,
                    'rawMinutes'     => $rawMinutes,
                    'roundedMinutes' => $roundedMinutes,
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
     * Build a lookup of the latest scan time for each (personal_number, date)
     * pair. Used to detect whether an employee has moved to another project
     * after an earlier one: the record with the greatest scan time wins, the
     * others are considered finished.
     *
     * The map covers ALL records (not just those matching a portal IČO) —
     * because if the employee scanned in at a non-matching project later on,
     * that still ends the earlier matching one. Records with no scan time at
     * all are represented by an empty string; ties (same scan time on two
     * records) leave all tied records as "latest", which is fine — the
     * aggregated `ongoing` flag is an OR across the day.
     *
     * @param array<array<string,mixed>> $records
     * @return array<string,string> key = "personal_number|YYYY-MM-DD", value = scan time string
     */
    private static function indexLatestScanPerEmployeeDay(array $records): array
    {
        $latest = [];

        foreach ($records as $record) {
            $date = $record['date'] ?? null;
            if (!is_string($date) || !self::isValidDate($date)) {
                continue;
            }
            $personalNumber = $record['employee']['personal_number'] ?? null;
            if ($personalNumber === null || $personalNumber === '') {
                continue;
            }

            $key = (string) $personalNumber . '|' . $date;
            $scanTime = self::extractScanTime($record);

            if (!isset($latest[$key]) || strcmp($scanTime, $latest[$key]) > 0) {
                $latest[$key] = $scanTime;
            }
        }

        return $latest;
    }

    /**
     * True iff this record's scan time equals the latest known scan time for
     * the same (employee, date). If no record for this employee-day has a scan
     * time at all (map entry is ''), every record is considered latest and the
     * method falls back to the old "today → ongoing" behaviour — better than
     * silently dropping the ongoing flag when FreshQR omits the time.
     *
     * @param array<string,mixed> $record
     * @param array<string,string> $latestScanPerEmployeeDay
     */
    private static function isLatestScanForEmployeeDay(
        array $record,
        string $personalNumber,
        string $date,
        array $latestScanPerEmployeeDay
    ): bool {
        $key = $personalNumber . '|' . $date;
        $latest = $latestScanPerEmployeeDay[$key] ?? '';
        $scanTime = self::extractScanTime($record);

        return $scanTime === $latest;
    }

    /**
     * Pull the record's scan time. Prefers last_scan_time (the most recent
     * activity at that project); falls back to first_scan_time. Returns ''
     * when neither is present — callers comparing scan times treat the empty
     * string as "earliest known" (sorts first), and `formatScanTimeToHm`
     * returns null for it so the FE never renders a placeholder.
     *
     * @param array<string,mixed> $record
     */
    private static function extractScanTime(array $record): string
    {
        foreach (['last_scan_time', 'first_scan_time'] as $field) {
            $value = $record[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Merge the optional per-record `notes` array into a single string, joined
     * by a blank line so each note reads as its own paragraph in the popover.
     * FreshQR will deliver a list of separate worker remarks per cleaning;
     * we collapse them server-side so the FE keeps treating `note` as a single
     * presentation field.
     *
     * Empty / non-string entries are dropped; the whole field returns null when
     * nothing usable remains, so the FE's `v-if="c.note"` simply omits the row.
     *
     * @param array<string,mixed> $record
     */
    private static function extractNote(array $record): ?string
    {
        $notes = $record['notes'] ?? null;
        if (!is_array($notes)) {
            return null;
        }

        $cleaned = [];
        foreach ($notes as $note) {
            if (!is_string($note)) {
                continue;
            }
            $trimmed = trim($note);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned === [] ? null : implode("\n\n", $cleaned);
    }

    /**
     * End-of-cleaning time for the Detailed-mode payload. Returns null when
     * last_scan_time is missing OR equals first_scan_time exactly — both mean
     * "no second scan recorded yet, treat as still on-site".
     *
     * @param array<string,mixed> $record
     */
    private static function computeEndTime(array $record): ?string
    {
        $first = is_string($record['first_scan_time'] ?? null) ? (string) $record['first_scan_time'] : '';
        $last = is_string($record['last_scan_time'] ?? null) ? (string) $record['last_scan_time'] : '';

        if ($last === '' || $last === $first) {
            return null;
        }
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

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
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
}
