<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Produces the per-IČO hourly summary shown above the Docházka calendar.
 *
 * One row per IČO whose billing_model is 'hourly'. Hours are summed from the
 * per-cleaning durations already computed by FreshQRService; we prefer
 * roundedMinutes (the billable value when the IČO has rounding rules) and
 * fall back to rawMinutes for IČOs that don't. Cleanings where both are null
 * (ongoing visits with no end time, or basic-mode IČOs that never expose a
 * duration) contribute zero — they simply don't move the total.
 *
 * The output row exists even when the IČO had zero cleanings in the period:
 * a hourly-billed client expects to see "0 h" on a quiet month, not a missing
 * card that's easily mistaken for a misconfiguration.
 */
class AttendanceSummaryService
{
    /**
     * @param array<array<string,mixed>> $companies      Rows from CompanyRepository — must carry
     *                                                   `registration_number`, `name`,
     *                                                   `billing_model`, `hourly_rate`.
     * @param array<array<string,mixed>> $cleaningDays   Output of FreshQRService::getCleaningDaysForUser
     *                                                   (the `cleaningDays` field).
     * @return list<array{ico:string,companyName:string,hourlyRate:?float,totalMinutes:int}>
     */
    public static function buildHourlySummary(array $companies, array $cleaningDays): array
    {
        $minutesByIco = self::sumMinutesByIco($cleaningDays);

        $rows = [];
        foreach ($companies as $company) {
            if (!self::isHourlyBilled($company)) {
                continue;
            }
            $ico = self::sanitiseIco($company['registration_number'] ?? null);
            if ($ico === null) {
                continue;
            }
            $rows[] = [
                'ico' => $ico,
                'companyName' => self::companyName($company),
                'hourlyRate' => self::normaliseHourlyRate($company['hourly_rate'] ?? null),
                'totalMinutes' => $minutesByIco[$ico] ?? 0,
            ];
        }
        return $rows;
    }

    /**
     * @param array<array<string,mixed>> $cleaningDays
     * @return array<string,int>
     */
    private static function sumMinutesByIco(array $cleaningDays): array
    {
        $totals = [];
        foreach ($cleaningDays as $day) {
            $cleanings = $day['cleanings'] ?? null;
            if (!is_array($cleanings)) {
                continue;
            }
            foreach ($cleanings as $cleaning) {
                $ico = self::sanitiseIco($cleaning['ico'] ?? null);
                if ($ico === null) {
                    continue;
                }
                $minutes = self::pickMinutes($cleaning);
                if ($minutes === null) {
                    continue;
                }
                $totals[$ico] = ($totals[$ico] ?? 0) + $minutes;
            }
        }
        return $totals;
    }

    /**
     * roundedMinutes is the source of truth when present — a sub-threshold visit
     * rounded down to 0 is a billable zero, not a "missing" record that should
     * fall back to rawMinutes. rawMinutes is the fallback only when no rounding
     * rule was applied (the field is null). Both null → null → skip.
     *
     * @param array<string,mixed> $cleaning
     */
    private static function pickMinutes(array $cleaning): ?int
    {
        $rounded = $cleaning['roundedMinutes'] ?? null;
        if (is_int($rounded) && $rounded >= 0) {
            return $rounded;
        }
        $raw = $cleaning['rawMinutes'] ?? null;
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $company
     */
    private static function isHourlyBilled(array $company): bool
    {
        $billing = $company['billing_model'] ?? null;
        return is_string($billing) && strtolower(trim($billing)) === 'hourly';
    }

    /**
     * @param array<string,mixed> $company
     */
    private static function companyName(array $company): string
    {
        $name = $company['name'] ?? null;
        if (is_string($name)) {
            $trimmed = trim($name);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        // Fallback so the FE never renders an empty header.
        return (string) ($company['registration_number'] ?? '');
    }

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
     * Hourly rate is stored as DECIMAL(10,2) string from PDO; the FE expects a
     * number or null. Treat 0 and below as "not set" so the FE can hide the
     * amount row without checking for a magic zero — keeps the contract simple.
     */
    private static function normaliseHourlyRate(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $float = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($float === false || $float <= 0) {
            return null;
        }
        return $float;
    }
}
