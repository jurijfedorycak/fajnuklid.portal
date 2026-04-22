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
 *      right now (ongoing = today's date) or already happened. Durations,
 *      scan times and employee identities never cross the wire.
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

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'ongoing' => $date === $today,
                ];
            }
        }

        ksort($byDate);

        return array_values($byDate);
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
