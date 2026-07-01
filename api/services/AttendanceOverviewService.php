<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aggregates the Docházka overview shown above the calendar: visit counts and
 * (for detailed-mode IČOs) worked-time totals over a selectable period —
 * day / week / month / quarter / year — plus the same figures for the previous
 * equivalent period so the FE can render a comparison.
 *
 * Two disclosure tiers, inherited from FreshQRService:
 *   - visit count (number of days a cleaning happened) is available for every
 *     IČO, basic and detailed alike.
 *   - worked time and the per-object breakdown come from `cleanings[]`, which
 *     FreshQRService only populates for detailed-mode IČOs. Basic-mode clients
 *     therefore see visit counts but no times — `hasTimeData` is false and
 *     `perObject` is empty, and the FE hides the time metrics accordingly.
 */
class AttendanceOverviewService
{
    public const PERIODS = ['day', 'week', 'month', 'quarter', 'year'];
    public const DEFAULT_PERIOD = 'month';

    /**
     * Normalise an arbitrary period string to a supported value, defaulting to
     * 'month' for anything unexpected (bogus query param, future addition).
     */
    public static function normalisePeriod(mixed $raw): string
    {
        if (is_string($raw) && in_array($raw, self::PERIODS, true)) {
            return $raw;
        }
        return self::DEFAULT_PERIOD;
    }

    /**
     * The calendar range for the period that contains $today. Full calendar
     * periods (whole month/quarter/year, ISO week Mon–Sun); since FreshQR never
     * carries future data, counting over the whole period is identical to
     * truncating at today.
     *
     * @return array{from:\DateTimeImmutable,to:\DateTimeImmutable}
     */
    public static function currentRange(string $period, \DateTimeImmutable $today): array
    {
        $day = $today->setTime(0, 0);

        switch ($period) {
            case 'day':
                return ['from' => $day, 'to' => $day];
            case 'week':
                $monday = $day->modify('monday this week');
                return ['from' => $monday, 'to' => $monday->modify('+6 days')];
            case 'quarter':
                $startMonth = (intdiv((int) $day->format('n') - 1, 3) * 3) + 1;
                $from = $day->setDate((int) $day->format('Y'), $startMonth, 1);
                return ['from' => $from, 'to' => $from->modify('+2 months')->modify('last day of this month')];
            case 'year':
                $from = $day->setDate((int) $day->format('Y'), 1, 1);
                return ['from' => $from, 'to' => $day->setDate((int) $day->format('Y'), 12, 31)];
            case 'month':
            default:
                return [
                    'from' => $day->modify('first day of this month'),
                    'to' => $day->modify('last day of this month'),
                ];
        }
    }

    /**
     * The full period immediately before currentRange — last week/month/quarter/
     * year, or yesterday for the day view. Used as the comparison baseline.
     *
     * @return array{from:\DateTimeImmutable,to:\DateTimeImmutable}
     */
    public static function previousRange(string $period, \DateTimeImmutable $today): array
    {
        $current = self::currentRange($period, $today);

        switch ($period) {
            case 'day':
                $prev = $current['from']->modify('-1 day');
                return ['from' => $prev, 'to' => $prev];
            case 'week':
                return [
                    'from' => $current['from']->modify('-7 days'),
                    'to' => $current['to']->modify('-7 days'),
                ];
            case 'quarter':
                $from = $current['from']->modify('-3 months');
                return ['from' => $from, 'to' => $from->modify('+2 months')->modify('last day of this month')];
            case 'year':
                $year = (int) $current['from']->format('Y') - 1;
                return [
                    'from' => $current['from']->setDate($year, 1, 1),
                    'to' => $current['from']->setDate($year, 12, 31),
                ];
            case 'month':
            default:
                $anchor = $current['from']->modify('-1 month');
                return [
                    'from' => $anchor->modify('first day of this month'),
                    'to' => $anchor->modify('last day of this month'),
                ];
        }
    }

    /**
     * Aggregate the slice of $cleaningDays that falls within [$fromStr, $toStr].
     * The caller is expected to pass a cleaningDays array already covering (at
     * least) the requested window — this method never fetches, it only sums.
     *
     * @param array<array<string,mixed>> $cleaningDays  FreshQRService/Demo output
     * @param array<string,string>       $icoToName     ico => company display name
     * @return array{
     *   visitCount:int,
     *   ongoingCount:int,
     *   totalMinutes:int,
     *   hasTimeData:bool,
     *   perObject:list<array{ico:string,companyName:string,visitCount:int,totalMinutes:int}>
     * }
     */
    public static function aggregate(array $cleaningDays, string $fromStr, string $toStr, array $icoToName): array
    {
        $visitCount = 0;
        $ongoingCount = 0;
        $totalMinutes = 0;
        $hasTimeData = false;
        $perObject = [];

        foreach ($cleaningDays as $day) {
            $date = $day['date'] ?? null;
            if (!is_string($date) || $date < $fromStr || $date > $toStr) {
                continue;
            }

            $visitCount++;
            if (!empty($day['ongoing'])) {
                $ongoingCount++;
            }

            // Per-object VISIT counts come from `icos` — the distinct client IČOs
            // cleaned that day across BOTH modes — so basic-mode IČOs are counted
            // too (one visit per IČO per day, the "úklidový den" unit).
            $dayIcos = is_array($day['icos'] ?? null) ? $day['icos'] : [];
            foreach ($dayIcos as $rawIco) {
                $ico = self::sanitiseIco($rawIco);
                if ($ico === null) {
                    continue;
                }
                if (!isset($perObject[$ico])) {
                    $perObject[$ico] = ['visitCount' => 0, 'totalMinutes' => 0];
                }
                $perObject[$ico]['visitCount']++;
            }

            // WORKED TIME comes only from `cleanings[]`, which FreshQRService
            // populates for detailed-mode IČOs only — basic IČOs contribute
            // visits above but never minutes.
            $cleanings = is_array($day['cleanings'] ?? null) ? $day['cleanings'] : [];
            foreach ($cleanings as $cleaning) {
                $ico = self::sanitiseIco($cleaning['ico'] ?? null);
                if ($ico === null) {
                    continue;
                }
                $minutes = self::pickMinutes($cleaning);
                if ($minutes === null) {
                    continue;
                }
                // A detailed cleaning can only exist for a day whose `icos`
                // already listed this IČO, so the bucket is present; guard
                // defensively anyway in case of malformed input.
                if (!isset($perObject[$ico])) {
                    $perObject[$ico] = ['visitCount' => 0, 'totalMinutes' => 0];
                }
                $perObject[$ico]['totalMinutes'] += $minutes;
                $totalMinutes += $minutes;
                $hasTimeData = true;
            }
        }

        $rows = [];
        foreach ($perObject as $ico => $agg) {
            // PHP coerces numeric-string array keys to int — coerce back so the
            // IČO and its fallback company name stay strings on the wire.
            $ico = (string) $ico;
            $rows[] = [
                'ico' => $ico,
                'companyName' => $icoToName[$ico] ?? $ico,
                'visitCount' => $agg['visitCount'],
                'totalMinutes' => $agg['totalMinutes'],
            ];
        }
        usort($rows, static fn ($a, $b) => strcmp($a['companyName'], $b['companyName']));

        return [
            'visitCount' => $visitCount,
            'ongoingCount' => $ongoingCount,
            'totalMinutes' => $totalMinutes,
            'hasTimeData' => $hasTimeData,
            'perObject' => $rows,
        ];
    }

    /**
     * Mirror of AttendanceSummaryService::pickMinutes — roundedMinutes is the
     * billable truth when present (0 is a valid rounded-down value); rawMinutes
     * is the fallback only when no rounding rule applied.
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
}
