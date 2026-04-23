<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\FreshQRClient;
use App\Repositories\CompanyRepository;
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
 *   3. The client sees only: the date, and whether the cleaning is happening
 *      right now (ongoing) or already happened. Durations, scan times and
 *      employee identities never cross the wire.
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

    public function __construct()
    {
        $this->client = new FreshQRClient();
        $this->companyRepo = new CompanyRepository();
        $this->employeeRepo = new EmployeeRepository();
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
     *   'active'       => bool,                                     // FreshQR configured AND user has at least one IČO
     *   'cleaningDays' => [['date' => 'YYYY-MM-DD', 'ongoing' => bool], ...],
     * ]
     *
     * When FreshQR isn't configured or the API call fails the method degrades
     * gracefully to active=false + empty list — the FE then renders the
     * onboarding fallback rather than a broken calendar.
     */
    public function getCleaningDaysForUser(int $userId, int $year, int $month): array
    {
        if (!$this->client->isConfigured()) {
            return ['active' => false, 'cleaningDays' => [], 'error' => null];
        }

        $companies = $this->companyRepo->findByUserId($userId);
        $icos = self::extractIcos($companies);

        if (empty($icos)) {
            return ['active' => false, 'cleaningDays' => [], 'error' => null];
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
                'error' => 'Docházku se nepodařilo načíst. Zkuste to prosím později.',
            ];
        }

        $allowedPersonalIds = array_fill_keys($this->employeeRepo->getAllPersonalIds(), true);

        $cleaningDays = self::buildCleaningDays($records, $icos, $allowedPersonalIds, self::today());

        return [
            'active' => true,
            'cleaningDays' => $cleaningDays,
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
            $ico = trim((string) ($c['registration_number'] ?? ''));
            if ($ico === '' || !ctype_digit($ico)) {
                continue;
            }
            $len = strlen($ico);
            if ($len < 4 || $len > 10) {
                continue;
            }
            $icos[] = $ico;
        }

        return array_values(array_unique($icos));
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
     * @param array<array<string,mixed>> $records          Raw data[] from /v1/reports/projects
     * @param list<string>               $icos             Portal user's IČOs
     * @param array<string,mixed>        $allowedPersonalIds  array_flip of portal personal_ids (keys = ids)
     * @param string                     $today            YYYY-MM-DD, injected for testability
     * @return list<array{date:string,ongoing:bool}>
     */
    public static function buildCleaningDays(
        array $records,
        array $icos,
        array $allowedPersonalIds,
        string $today
    ): array {
        $latestScanPerEmployeeDay = self::indexLatestScanPerEmployeeDay($records);

        $byDate = [];

        foreach ($records as $record) {
            $date = $record['date'] ?? null;
            if (!is_string($date) || !self::isValidDate($date)) {
                continue;
            }

            $projectName = (string) ($record['project']['name'] ?? '');
            if (!self::projectMatchesAnyIco($projectName, $icos)) {
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
                ];
            } elseif ($isOngoing) {
                // Another employee on the same day may still be active even if
                // this specific record was already overtaken; OR the flags.
                $byDate[$date]['ongoing'] = true;
            }
        }

        ksort($byDate);

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
     * when neither is present — callers must treat empty as "unknown".
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
     * True when the project name contains $ico as an isolated digit run — i.e.
     * not surrounded by other digits on either side. Guards against one IČO
     * matching inside another (8-digit IČO 12345678 matching inside the
     * 9-digit string 123456789 was a cross-tenant leak before this).
     */
    private static function projectMatchesAnyIco(string $projectName, array $icos): bool
    {
        if ($projectName === '') {
            return false;
        }

        foreach ($icos as $ico) {
            if ($ico === '') {
                continue;
            }
            $pattern = '/(?<!\d)' . preg_quote($ico, '/') . '(?!\d)/u';
            if (preg_match($pattern, $projectName) === 1) {
                return true;
            }
        }

        return false;
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

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }
}
