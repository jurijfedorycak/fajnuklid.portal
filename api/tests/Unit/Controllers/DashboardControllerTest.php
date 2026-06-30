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
}
