<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Synthesises a believable cleaning history for clients flagged as demo accounts.
 *
 * Schedule: Tue + Thu in ISO-odd weeks, Wed + Sat in ISO-even weeks. Past dates
 * are emitted as completed; today is always emitted as ongoing regardless of the
 * schedule, so the demo never looks like nothing is happening today; future
 * dates are dropped so the calendar keeps the "past only" feel of the real
 * FreshQR-driven view.
 *
 * Each emitted day carries a `cleanings[]` array — same shape as the real
 * Detailed-mode FreshQR output (including `rawMinutes` / `roundedMinutes`) so
 * downstream code (calendar popover, hourly summary) flows through a single
 * code path. Two synthetic employees alternate across the schedule.
 */
class DemoAttendanceService
{
    private const DEMO_EMPLOYEES = [
        ['name' => 'Anna N.', 'startTime' => '08:00', 'endTime' => '11:30'],
        ['name' => 'Petr K.', 'startTime' => '13:00', 'endTime' => '15:30'],
    ];

    public const DEMO_ICO = '12345678';
    public const DEMO_COMPANY_NAME = 'Ukázková firma s.r.o.';
    public const DEMO_HOURLY_RATE = '250.00';

    /**
     * Companies-shape row matching CompanyRepository output, for callers that
     * need to run summary helpers on demo data without going through the DB.
     *
     * @return list<array<string,mixed>>
     */
    public static function syntheticCompanies(): array
    {
        return [[
            'registration_number' => self::DEMO_ICO,
            'name' => self::DEMO_COMPANY_NAME,
            'billing_model' => 'hourly',
            'hourly_rate' => self::DEMO_HOURLY_RATE,
        ]];
    }

    /**
     * Build the cleaningDays list for a single calendar month under the demo schedule.
     *
     * @return list<array{date:string,ongoing:bool,icos:list<string>,cleanings:list<array{employee:string,startTime:?string,endTime:?string,ico:string,ongoing:bool}>}>
     */
    public static function buildCleaningDays(int $year, int $month, \DateTimeImmutable $today): array
    {
        $todayStr = $today->format('Y-m-d');
        $todayY = (int) $today->format('Y');
        $todayM = (int) $today->format('n');
        $todayD = (int) $today->format('j');

        $first = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
        if ($first === false) {
            return [];
        }
        $daysInMonth = (int) $first->format('t');

        $result = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $first->setDate($year, $month, $d);
            $dateStr = $date->format('Y-m-d');

            if ($dateStr === $todayStr) {
                $result[] = [
                    'date' => $dateStr,
                    'ongoing' => true,
                    'icos' => [self::DEMO_ICO],
                    // Today: morning cleaning is finished, afternoon one is "still on-site"
                    // (endTime null) — gives the demo a believable mid-day mix.
                    'cleanings' => [
                        self::buildCleaning(
                            self::DEMO_EMPLOYEES[0]['name'],
                            self::DEMO_EMPLOYEES[0]['startTime'],
                            self::DEMO_EMPLOYEES[0]['endTime'],
                            false
                        ),
                        self::buildCleaning(
                            self::DEMO_EMPLOYEES[1]['name'],
                            self::DEMO_EMPLOYEES[1]['startTime'],
                            null,
                            true
                        ),
                    ],
                ];
                continue;
            }

            // Compare by date components rather than timestamps so a late-evening
            // request near midnight in another timezone doesn't accidentally include
            // tomorrow as "past".
            if ($year > $todayY
                || ($year === $todayY && $month > $todayM)
                || ($year === $todayY && $month === $todayM && $d > $todayD)
            ) {
                continue;
            }

            $isoWeek = (int) $date->format('W');
            $dayOfWeek = (int) $date->format('N');

            $matches = ($isoWeek % 2 === 1)
                ? ($dayOfWeek === 2 || $dayOfWeek === 4)
                : ($dayOfWeek === 3 || $dayOfWeek === 6);

            if ($matches) {
                $result[] = [
                    'date' => $dateStr,
                    'ongoing' => false,
                    'icos' => [self::DEMO_ICO],
                    'cleanings' => self::buildDemoCleanings($date),
                ];
            }
        }

        return $result;
    }

    /**
     * Range variant used by the Docházka overview. Iterates every calendar month
     * the [$from, $to] window touches, reuses buildCleaningDays for each, and
     * filters the merged result to the inclusive range. Keeps the synthetic
     * schedule identical to what the month-scoped calendar shows.
     *
     * @return list<array{date:string,ongoing:bool,cleanings:list<array<string,mixed>>}>
     */
    public static function buildCleaningDaysForRange(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $today
    ): array {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');

        $result = [];
        // Walk month-by-month from the first day of $from's month up to $to.
        $cursor = $from->modify('first day of this month')->setTime(0, 0);
        $end = $to->modify('first day of this month')->setTime(0, 0);
        while ($cursor <= $end) {
            $days = self::buildCleaningDays(
                (int) $cursor->format('Y'),
                (int) $cursor->format('n'),
                $today
            );
            foreach ($days as $day) {
                if ($day['date'] >= $fromStr && $day['date'] <= $toStr) {
                    $result[] = $day;
                }
            }
            $cursor = $cursor->modify('first day of next month');
        }

        return $result;
    }

    /**
     * Synthesise a believable per-day cleanings list. ISO-odd weeks get two
     * cleanings (morning + afternoon, two different workers); ISO-even weeks
     * get one.
     *
     * @return list<array{employee:string,startTime:?string,endTime:?string,ico:string,ongoing:bool}>
     */
    private static function buildDemoCleanings(\DateTimeImmutable $date): array
    {
        $isoWeek = (int) $date->format('W');
        $dayOfWeek = (int) $date->format('N');
        $isOddWeek = $isoWeek % 2 === 1;

        $employees = $isOddWeek
            ? [self::DEMO_EMPLOYEES[0], self::DEMO_EMPLOYEES[1]]
            : [self::DEMO_EMPLOYEES[$dayOfWeek === 3 ? 0 : 1]];

        $cleanings = [];
        foreach ($employees as $emp) {
            $cleanings[] = self::buildCleaning(
                $emp['name'],
                $emp['startTime'],
                $emp['endTime'],
                false
            );
        }
        return $cleanings;
    }

    /**
     * Build one cleaning entry in the shape FreshQRService produces in detailed
     * mode. `roundedMinutes` stays null — demo IČO has no rounding rules — so
     * the FE renders the raw time range, same as a real Detailed-mode IČO
     * without rules. `rawMinutes` is computed when both ends are known so the
     * hourly summary helper has something to sum on demo accounts.
     *
     * @return array{employee:string,startTime:?string,endTime:?string,ico:string,rawMinutes:?int,roundedMinutes:?int,roundedEndTime:?string,hasRoundingRules:bool,ongoing:bool}
     */
    private static function buildCleaning(
        string $employee,
        ?string $startTime,
        ?string $endTime,
        bool $ongoing
    ): array {
        return [
            'employee'         => $employee,
            'startTime'        => $startTime,
            'endTime'          => $endTime,
            'ico'              => self::DEMO_ICO,
            'rawMinutes'       => FreshQRService::computeDurationMinutes($startTime, $endTime),
            'roundedMinutes'   => null,
            // Demo IČO has no rounding rules — these stay null/false so the
            // controller's applyRoundingRedactions is a no-op for demo data.
            'roundedEndTime'   => null,
            'hasRoundingRules' => false,
            'ongoing'          => $ongoing,
        ];
    }
}
