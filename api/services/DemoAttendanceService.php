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
 */
class DemoAttendanceService
{
    /**
     * Build the cleaningDays list for a single calendar month under the demo schedule.
     *
     * @return list<array{date:string,ongoing:bool}>
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
                $result[] = ['date' => $dateStr, 'ongoing' => true];
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
                $result[] = ['date' => $dateStr, 'ongoing' => false];
            }
        }

        return $result;
    }
}
