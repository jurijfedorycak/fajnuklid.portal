<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DemoAttendanceService;
use Tests\TestCase;

class DemoAttendanceServiceTest extends TestCase
{
    private static function today(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testReturnsEmptyForFullyFutureMonth(): void
    {
        // Today is 2026-05-06; June 2026 is entirely in the future.
        $result = DemoAttendanceService::buildCleaningDays(2026, 6, self::today('2026-05-06'));

        $this->assertSame([], $result);
    }

    public function testReturnsTodayOngoingWhenTodayMatchesSchedule(): void
    {
        // 2026-04-21 is Tue, ISO week 17 (odd). Schedule says Tue+Thu — match.
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-04-21'));

        $todayEntry = self::findByDate($result, '2026-04-21');
        $this->assertNotNull($todayEntry, 'Today must be present in the result');
        $this->assertTrue($todayEntry['ongoing']);
    }

    public function testReturnsTodayOngoingEvenWhenTodayDoesNotMatchSchedule(): void
    {
        // 2026-04-19 is Sun. No week parity has Sun in its schedule, but the
        // "today is always ongoing" rule must still emit it.
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-04-19'));

        $todayEntry = self::findByDate($result, '2026-04-19');
        $this->assertNotNull($todayEntry);
        $this->assertTrue($todayEntry['ongoing']);
    }

    public function testIsoOddWeekScheduleEmitsTueAndThuOnly(): void
    {
        // April 2026 is fully in the past for today=2026-05-06.
        // Week 15 (Apr 6–12) is ISO-odd → expect Tue Apr 7 + Thu Apr 9, nothing else from that week.
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-05-06'));
        $dates = array_column($result, 'date');

        // Tue + Thu present
        $this->assertContains('2026-04-07', $dates);
        $this->assertContains('2026-04-09', $dates);
        // Mon, Wed, Fri, Sat, Sun absent
        $this->assertNotContains('2026-04-06', $dates);
        $this->assertNotContains('2026-04-08', $dates);
        $this->assertNotContains('2026-04-10', $dates);
        $this->assertNotContains('2026-04-11', $dates);
        $this->assertNotContains('2026-04-12', $dates);
    }

    public function testIsoEvenWeekScheduleEmitsWedAndSatOnly(): void
    {
        // Week 16 (Apr 13–19) is ISO-even → expect Wed Apr 15 + Sat Apr 18.
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-05-06'));
        $dates = array_column($result, 'date');

        $this->assertContains('2026-04-15', $dates);
        $this->assertContains('2026-04-18', $dates);
        $this->assertNotContains('2026-04-13', $dates);
        $this->assertNotContains('2026-04-14', $dates);
        $this->assertNotContains('2026-04-16', $dates);
        $this->assertNotContains('2026-04-17', $dates);
        $this->assertNotContains('2026-04-19', $dates);
    }

    public function testPastEntriesAreNotMarkedOngoing(): void
    {
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-05-06'));

        foreach ($result as $entry) {
            $this->assertFalse($entry['ongoing'], "Past entry {$entry['date']} should not be ongoing");
        }
    }

    public function testHandlesIsoWeekCrossYearBoundary(): void
    {
        // Jan 1 2026 is Thu. PHP format('W') returns 01 — week 1 is odd, so Thu matches.
        // today=2026-05-06 makes Jan 2026 fully past. The cross-year subtlety: ISO week 1
        // of 2026 begins Mon Dec 29 2025, so Jan 1–4 2026 inherit week 1 (odd) and the
        // Tue+Thu schedule applies; Jan 5 starts week 2 (even) → Wed+Sat.
        $result = DemoAttendanceService::buildCleaningDays(2026, 1, self::today('2026-05-06'));
        $dates = array_column($result, 'date');

        // Week 1 (odd) → Thu Jan 1 in, Sat Jan 3 out.
        $this->assertContains('2026-01-01', $dates);
        $this->assertNotContains('2026-01-03', $dates);
        // Week 2 (even) → Wed Jan 7 + Sat Jan 10 in, Tue Jan 6 + Thu Jan 8 out.
        $this->assertContains('2026-01-07', $dates);
        $this->assertContains('2026-01-10', $dates);
        $this->assertNotContains('2026-01-06', $dates);
        $this->assertNotContains('2026-01-08', $dates);
    }

    public function testFarPastMonthIsFullyPopulatedWithoutOngoing(): void
    {
        // Jan 2023 — pre-FreshQR but we don't gate on that for demo accounts.
        $result = DemoAttendanceService::buildCleaningDays(2023, 1, self::today('2026-05-06'));

        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertFalse($entry['ongoing']);
        }
        // Sanity: roughly 8–10 entries per month under the schedule.
        $this->assertGreaterThanOrEqual(7, count($result));
    }

    public function testResultIsSortedAscending(): void
    {
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-05-06'));
        $dates = array_column($result, 'date');
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
    }

    public function testFutureDaysWithinCurrentMonthAreSkipped(): void
    {
        // today = 2026-04-15 (Wed, even week → on schedule). Days after the 15th in April should not appear.
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-04-15'));
        $dates = array_column($result, 'date');

        // Today is present
        $this->assertContains('2026-04-15', $dates);
        // Future scheduled days (Apr 18 Sat, Apr 21 Tue, Apr 23 Thu) are NOT
        $this->assertNotContains('2026-04-18', $dates);
        $this->assertNotContains('2026-04-21', $dates);
        $this->assertNotContains('2026-04-23', $dates);
    }

    public function testEntriesContainOnlyDateAndOngoingKeys(): void
    {
        $result = DemoAttendanceService::buildCleaningDays(2026, 4, self::today('2026-05-06'));

        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertSame(['date', 'ongoing'], array_keys($entry));
        }
    }

    /**
     * @param list<array{date:string,ongoing:bool}> $entries
     */
    private static function findByDate(array $entries, string $date): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['date'] === $date) {
                return $entry;
            }
        }
        return null;
    }
}
