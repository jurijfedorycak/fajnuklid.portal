<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\DashboardController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit coverage for the private "cleaning in progress" summariser that feeds
 * the dashboard live banner. Reflection-based so the disclosure contract is
 * pinned without standing up a full request/response harness — the same
 * approach AttendanceControllerTest uses for its redaction helper.
 */
class DashboardControllerTest extends TestCase
{
    private const TODAY = '2026-06-30';

    private static function build(array $rawCleaningDays, array $icoToName = ['12345678' => 'Ukázková firma s.r.o.']): ?array
    {
        $method = new ReflectionMethod(DashboardController::class, 'buildOngoingCleaning');
        $method->setAccessible(true);
        return $method->invoke(null, $rawCleaningDays, self::TODAY, $icoToName);
    }

    private static function cleaning(array $overrides): array
    {
        return array_merge([
            'employee'         => 'Anna N.',
            'startTime'        => '08:00',
            'endTime'          => null,
            'note'             => null,
            'ico'              => '12345678',
            'rawMinutes'       => null,
            'roundedMinutes'   => null,
            'hasRoundingRules' => false,
            'ongoing'          => true,
        ], $overrides);
    }

    public function testReturnsNullWhenNoDayMatchesToday(): void
    {
        $days = [['date' => '2026-06-29', 'ongoing' => true, 'cleanings' => []]];
        $this->assertNull(self::build($days));
    }

    public function testReturnsNullWhenTodayIsNotOngoing(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => false,
            'cleanings' => [self::cleaning(['ongoing' => false])],
        ]];
        $this->assertNull(self::build($days));
    }

    public function testDetailedOngoingSurfacesObjectStartAndEmployee(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [self::cleaning(['startTime' => '13:00', 'employee' => 'Petr K.'])],
        ]];

        $result = self::build($days);
        $this->assertSame('Ukázková firma s.r.o.', $result['objectName']);
        $this->assertSame('13:00', $result['since']);
        $this->assertSame(['Petr K.'], $result['employees']);
    }

    public function testFinishedCleaningsAreIgnoredForDetails(): void
    {
        // Morning cleaning finished, afternoon still on-site — only the ongoing
        // one contributes its start/worker (mirrors the demo "today" shape).
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [
                self::cleaning(['employee' => 'Anna N.', 'startTime' => '08:00', 'endTime' => '11:30', 'ongoing' => false]),
                self::cleaning(['employee' => 'Petr K.', 'startTime' => '13:00', 'ongoing' => true]),
            ],
        ]];

        $result = self::build($days);
        $this->assertSame(['Petr K.'], $result['employees']);
        $this->assertSame('13:00', $result['since']);
    }

    public function testStartTimeIsHiddenWhenIcoHasRoundingRules(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [self::cleaning(['startTime' => '13:00', 'hasRoundingRules' => true])],
        ]];

        $result = self::build($days);
        $this->assertNull($result['since'], 'rounding rules hide the start so the displayed time can\'t drift');
        $this->assertSame(['Anna N.'], $result['employees'], 'the worker name is still disclosed in detailed mode');
    }

    public function testSinceIsTheEarliestOngoingStart(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [
                self::cleaning(['employee' => 'Petr K.', 'startTime' => '13:00']),
                self::cleaning(['employee' => 'Anna N.', 'startTime' => '09:15']),
            ],
        ]];

        $result = self::build($days);
        $this->assertSame('09:15', $result['since']);
    }

    public function testBasicModeOngoingReturnsDetailFreeTuple(): void
    {
        // Basic-mode IČO flips the day-level ongoing flag but exposes no
        // cleanings[] — the banner shows a generic message, nothing leaks.
        $days = [['date' => self::TODAY, 'ongoing' => true, 'cleanings' => []]];

        $result = self::build($days);
        $this->assertNull($result['objectName']);
        $this->assertNull($result['since']);
        $this->assertSame([], $result['employees']);
    }

    public function testMultipleObjectsAreJoinedAndDeduplicated(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [
                self::cleaning(['ico' => '12345678']),
                self::cleaning(['ico' => '87654321', 'employee' => 'Petr K.']),
                self::cleaning(['ico' => '12345678', 'employee' => 'Jan D.']),
            ],
        ]];

        $result = self::build($days, [
            '12345678' => 'Ukázková firma s.r.o.',
            '87654321' => 'Druhý objekt a.s.',
        ]);
        $this->assertSame('Ukázková firma s.r.o., Druhý objekt a.s.', $result['objectName']);
        $this->assertSame(['Anna N.', 'Petr K.', 'Jan D.'], $result['employees']);
    }

    public function testUnknownIcoLeavesObjectNameNull(): void
    {
        $days = [[
            'date' => self::TODAY,
            'ongoing' => true,
            'cleanings' => [self::cleaning(['ico' => '99999999'])],
        ]];

        $result = self::build($days);
        $this->assertNull($result['objectName']);
        $this->assertSame(['Anna N.'], $result['employees']);
    }

    private static function reshape(array $rawCleaningDays): array
    {
        $method = new ReflectionMethod(DashboardController::class, 'reshapeCleaningDaysForDashboard');
        $method->setAccessible(true);
        return $method->invoke(null, $rawCleaningDays);
    }

    /**
     * The dashboard renders a previous-month and a current-month calendar from
     * one merged array; reshape must preserve days from both months so the
     * previous-month grid isn't blank.
     */
    public function testReshapePreservesDaysAcrossPreviousAndCurrentMonth(): void
    {
        $merged = [
            ['date' => '2026-05-14', 'ongoing' => false, 'cleanings' => []],
            ['date' => '2026-06-02', 'ongoing' => false, 'cleanings' => []],
            ['date' => self::TODAY, 'ongoing' => true, 'cleanings' => []],
        ];

        $result = self::reshape($merged);
        $dates = array_column($result, 'date');
        $this->assertSame(['2026-05-14', '2026-06-02', self::TODAY], $dates);
        $this->assertSame('done', $result[0]['status']);
        $this->assertSame('done', $result[1]['status']);
        $this->assertSame('ongoing', $result[2]['status']);
    }

    public function testReshapeSurfacesFirstNonEmptyNoteAndSkipsInvalidDates(): void
    {
        $merged = [
            ['date' => '', 'ongoing' => false, 'cleanings' => []],
            ['date' => '2026-05-20', 'ongoing' => false, 'cleanings' => [
                ['note' => '   '],
                ['note' => 'Generální úklid'],
            ]],
        ];

        $result = self::reshape($merged);
        $this->assertCount(1, $result, 'blank/invalid date entries are dropped');
        $this->assertSame('2026-05-20', $result[0]['date']);
        $this->assertSame('Generální úklid', $result[0]['note']);
    }

    private static function previousYearMonth(string $date): array
    {
        $method = new ReflectionMethod(DashboardController::class, 'previousYearMonth');
        $method->setAccessible(true);
        return $method->invoke(null, new \DateTime($date));
    }

    public function testPreviousYearMonthWithinSameYear(): void
    {
        $this->assertSame([2026, 5], self::previousYearMonth('2026-06-30'));
    }

    public function testPreviousYearMonthRollsBackAcrossYearBoundary(): void
    {
        $this->assertSame([2025, 12], self::previousYearMonth('2026-01-15'));
    }

    public function testPreviousYearMonthAvoidsDayOverflow(): void
    {
        // Naive "-1 month" from 31 March lands in March again (no 31 Feb);
        // anchoring on "first day of last month" must give February.
        $this->assertSame([2026, 2], self::previousYearMonth('2026-03-31'));
    }
}
