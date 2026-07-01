<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceOverviewService;
use Tests\TestCase;

class AttendanceOverviewServiceTest extends TestCase
{
    private static function day(string $date, bool $ongoing, array $cleanings = [], ?array $icos = null): array
    {
        // Mirror FreshQRService: `icos` lists every cleaned IČO that day (both
        // modes). When not given explicitly, derive it from the detailed
        // cleanings so detailed-mode fixtures stay terse.
        if ($icos === null) {
            $icos = [];
            foreach ($cleanings as $c) {
                $ico = $c['ico'] ?? null;
                if (is_string($ico) && !in_array($ico, $icos, true)) {
                    $icos[] = $ico;
                }
            }
        }
        return ['date' => $date, 'ongoing' => $ongoing, 'icos' => $icos, 'cleanings' => $cleanings];
    }

    private static function cleaning(string $ico, ?int $rawMinutes = null, ?int $roundedMinutes = null): array
    {
        return [
            'employee' => 'Anna N.',
            'startTime' => '08:00',
            'endTime' => '11:30',
            'ico' => $ico,
            'rawMinutes' => $rawMinutes,
            'roundedMinutes' => $roundedMinutes,
            'ongoing' => false,
        ];
    }

    private static function today(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 09:00:00');
    }

    // normalisePeriod

    public function testNormalisePeriodAcceptsSupportedValues(): void
    {
        foreach (AttendanceOverviewService::PERIODS as $p) {
            $this->assertSame($p, AttendanceOverviewService::normalisePeriod($p));
        }
    }

    public function testNormalisePeriodDefaultsToMonthForJunk(): void
    {
        $this->assertSame('month', AttendanceOverviewService::normalisePeriod('decade'));
        $this->assertSame('month', AttendanceOverviewService::normalisePeriod(null));
        $this->assertSame('month', AttendanceOverviewService::normalisePeriod(42));
    }

    // currentRange

    public function testCurrentRangeDayIsSingleDay(): void
    {
        $r = AttendanceOverviewService::currentRange('day', self::today('2026-07-01'));
        $this->assertSame('2026-07-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-07-01', $r['to']->format('Y-m-d'));
    }

    public function testCurrentRangeWeekIsMondayToSunday(): void
    {
        // 2026-07-01 is a Wednesday → ISO week Mon 2026-06-29 .. Sun 2026-07-05.
        $r = AttendanceOverviewService::currentRange('week', self::today('2026-07-01'));
        $this->assertSame('2026-06-29', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-07-05', $r['to']->format('Y-m-d'));
    }

    public function testCurrentRangeMonthCoversWholeMonth(): void
    {
        $r = AttendanceOverviewService::currentRange('month', self::today('2026-07-15'));
        $this->assertSame('2026-07-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-07-31', $r['to']->format('Y-m-d'));
    }

    public function testCurrentRangeQuarterCoversWholeQuarter(): void
    {
        // July → Q3 = Jul 1 .. Sep 30.
        $r = AttendanceOverviewService::currentRange('quarter', self::today('2026-07-15'));
        $this->assertSame('2026-07-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-09-30', $r['to']->format('Y-m-d'));
    }

    public function testCurrentRangeQuarterFirstQuarterBoundary(): void
    {
        // February → Q1 = Jan 1 .. Mar 31.
        $r = AttendanceOverviewService::currentRange('quarter', self::today('2026-02-10'));
        $this->assertSame('2026-01-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-03-31', $r['to']->format('Y-m-d'));
    }

    public function testCurrentRangeYearCoversWholeYear(): void
    {
        $r = AttendanceOverviewService::currentRange('year', self::today('2026-07-15'));
        $this->assertSame('2026-01-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-12-31', $r['to']->format('Y-m-d'));
    }

    // previousRange

    public function testPreviousRangeDayIsYesterday(): void
    {
        $r = AttendanceOverviewService::previousRange('day', self::today('2026-07-01'));
        $this->assertSame('2026-06-30', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-06-30', $r['to']->format('Y-m-d'));
    }

    public function testPreviousRangeWeekIsPriorIsoWeek(): void
    {
        $r = AttendanceOverviewService::previousRange('week', self::today('2026-07-01'));
        $this->assertSame('2026-06-22', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-06-28', $r['to']->format('Y-m-d'));
    }

    public function testPreviousRangeMonthRollsBackAcrossYear(): void
    {
        $r = AttendanceOverviewService::previousRange('month', self::today('2026-01-10'));
        $this->assertSame('2025-12-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2025-12-31', $r['to']->format('Y-m-d'));
    }

    public function testPreviousRangeMonthHandlesShortFebruary(): void
    {
        // Anchoring on "first day of" avoids the classic 31 Mar -1 month = 3 Mar bug.
        $r = AttendanceOverviewService::previousRange('month', self::today('2026-03-31'));
        $this->assertSame('2026-02-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2026-02-28', $r['to']->format('Y-m-d'));
    }

    public function testPreviousRangeQuarterRollsBackAcrossYear(): void
    {
        // Q1 2026 → previous quarter Q4 2025 = Oct 1 .. Dec 31 2025.
        $r = AttendanceOverviewService::previousRange('quarter', self::today('2026-02-10'));
        $this->assertSame('2025-10-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2025-12-31', $r['to']->format('Y-m-d'));
    }

    public function testPreviousRangeYearIsPriorYear(): void
    {
        $r = AttendanceOverviewService::previousRange('year', self::today('2026-07-15'));
        $this->assertSame('2025-01-01', $r['from']->format('Y-m-d'));
        $this->assertSame('2025-12-31', $r['to']->format('Y-m-d'));
    }

    // aggregate — counts

    public function testAggregateCountsVisitsWithinRangeOnly(): void
    {
        $days = [
            self::day('2026-06-30', false),           // before range
            self::day('2026-07-01', false),
            self::day('2026-07-15', true),
            self::day('2026-08-01', false),           // after range
        ];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', []);
        $this->assertSame(2, $agg['visitCount']);
        $this->assertSame(1, $agg['ongoingCount']);
    }

    public function testAggregateBoundariesAreInclusive(): void
    {
        $days = [
            self::day('2026-07-01', false),
            self::day('2026-07-31', false),
        ];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', []);
        $this->assertSame(2, $agg['visitCount']);
    }

    // aggregate — worked time (detailed)

    public function testAggregatePrefersRoundedMinutesAndSumsTotal(): void
    {
        $days = [
            self::day('2026-07-02', false, [self::cleaning('12345678', 137, 150)]),
            self::day('2026-07-03', false, [self::cleaning('12345678', 90, null)]),
        ];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', ['12345678' => 'Firma A']);
        $this->assertTrue($agg['hasTimeData']);
        // 150 (rounded) + 90 (raw fallback) = 240
        $this->assertSame(240, $agg['totalMinutes']);
    }

    public function testAggregateBasicModeCountsVisitsPerObjectWithoutTime(): void
    {
        // Basic-mode days carry `icos` but empty cleanings[] → the object's
        // visits count, but no worked time ever surfaces.
        $days = [
            self::day('2026-07-02', false, [], ['12345678']),
            self::day('2026-07-03', false, [], ['12345678']),
        ];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', ['12345678' => 'Firma A']);
        $this->assertSame(2, $agg['visitCount']);
        $this->assertFalse($agg['hasTimeData']);
        $this->assertSame(0, $agg['totalMinutes']);
        $this->assertCount(1, $agg['perObject']);
        $this->assertSame('Firma A', $agg['perObject'][0]['companyName']);
        $this->assertSame(2, $agg['perObject'][0]['visitCount']);
        $this->assertSame(0, $agg['perObject'][0]['totalMinutes']);
    }

    public function testAggregateMixedModeCountsBasicVisitsAndDetailedTime(): void
    {
        // Same day, two IČOs: one detailed (visits + time), one basic (visits
        // only via `icos`). Both appear in perObject; only the detailed one has
        // minutes, so a basic IČO never leaks a duration.
        $days = [
            self::day('2026-07-02', false, [self::cleaning('12345678', 120, null)], ['12345678', '99999999']),
        ];
        $agg = AttendanceOverviewService::aggregate(
            $days,
            '2026-07-01',
            '2026-07-31',
            ['12345678' => 'Detail s.r.o.', '99999999' => 'Basic a.s.']
        );
        $this->assertTrue($agg['hasTimeData']);
        $this->assertSame(120, $agg['totalMinutes']);

        $byIco = [];
        foreach ($agg['perObject'] as $r) {
            $byIco[$r['ico']] = $r;
        }
        $this->assertSame(1, $byIco['12345678']['visitCount']);
        $this->assertSame(120, $byIco['12345678']['totalMinutes']);
        $this->assertSame(1, $byIco['99999999']['visitCount']);
        $this->assertSame(0, $byIco['99999999']['totalMinutes'], 'Basic IČO must never carry a duration');
    }

    // aggregate — per-object breakdown

    public function testAggregatePerObjectCountsOneVisitPerIcoPerDay(): void
    {
        // Two cleaner records for the same IČO on the same day = one visit.
        $days = [
            self::day('2026-07-02', false, [
                self::cleaning('12345678', 60, null),
                self::cleaning('12345678', 30, null),
            ]),
            self::day('2026-07-04', false, [self::cleaning('12345678', 60, null)]),
        ];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', ['12345678' => 'Firma A']);
        $this->assertCount(1, $agg['perObject']);
        $this->assertSame('Firma A', $agg['perObject'][0]['companyName']);
        $this->assertSame(2, $agg['perObject'][0]['visitCount']);
        $this->assertSame(150, $agg['perObject'][0]['totalMinutes']);
    }

    public function testAggregatePerObjectSplitsByIcoAndSortsByName(): void
    {
        $days = [
            self::day('2026-07-02', false, [
                self::cleaning('99999999', 60, null),
                self::cleaning('12345678', 30, null),
            ]),
        ];
        $agg = AttendanceOverviewService::aggregate(
            $days,
            '2026-07-01',
            '2026-07-31',
            ['12345678' => 'Zebra s.r.o.', '99999999' => 'Alfa a.s.']
        );
        $this->assertCount(2, $agg['perObject']);
        // Sorted alphabetically by company name.
        $this->assertSame('Alfa a.s.', $agg['perObject'][0]['companyName']);
        $this->assertSame('Zebra s.r.o.', $agg['perObject'][1]['companyName']);
    }

    public function testAggregatePerObjectFallsBackToIcoWhenNameMissing(): void
    {
        $days = [self::day('2026-07-02', false, [self::cleaning('12345678', 60, null)])];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', []);
        $this->assertSame('12345678', $agg['perObject'][0]['companyName']);
    }

    public function testAggregateRoundedZeroMinutesStillCountsVisitAndTimeData(): void
    {
        // A sub-threshold visit rounded down to 0 is a billable zero, not "missing".
        $days = [self::day('2026-07-02', false, [self::cleaning('12345678', 3, 0)])];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', ['12345678' => 'Firma A']);
        $this->assertSame(1, $agg['perObject'][0]['visitCount']);
        $this->assertSame(0, $agg['perObject'][0]['totalMinutes']);
        $this->assertTrue($agg['hasTimeData']);
    }

    public function testAggregateIgnoresMalformedIco(): void
    {
        $days = [self::day('2026-07-02', false, [self::cleaning('12', 60, null)])];
        $agg = AttendanceOverviewService::aggregate($days, '2026-07-01', '2026-07-31', []);
        $this->assertSame(1, $agg['visitCount']);
        $this->assertSame([], $agg['perObject']);
    }

    public function testAggregateEmptyInput(): void
    {
        $agg = AttendanceOverviewService::aggregate([], '2026-07-01', '2026-07-31', []);
        $this->assertSame(0, $agg['visitCount']);
        $this->assertSame(0, $agg['ongoingCount']);
        $this->assertSame(0, $agg['totalMinutes']);
        $this->assertFalse($agg['hasTimeData']);
        $this->assertSame([], $agg['perObject']);
    }
}
