<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TimeRoundingService;
use Tests\TestCase;

class TimeRoundingServiceTest extends TestCase
{
    /**
     * Build a rule row with the right shape; defaults to a no-op so individual
     * tests only override the field they're exercising.
     *
     * @return array{threshold_minutes:int,interval_minutes:int,direction:string}
     */
    private static function rule(int $threshold, int $interval, string $direction): array
    {
        return [
            'threshold_minutes' => $threshold,
            'interval_minutes' => $interval,
            'direction' => $direction,
        ];
    }

    // ── Empty / no-op inputs ──────────────────────────────────────────────────

    public function testNoRulesReturnsInputUnchanged(): void
    {
        $this->assertSame(75, TimeRoundingService::roundDuration(75, []));
    }

    public function testZeroMinutesReturnsZero(): void
    {
        $rules = [self::rule(0, 60, 'up')];
        $this->assertSame(0, TimeRoundingService::roundDuration(0, $rules));
    }

    public function testNegativeMinutesReturnedUnchanged(): void
    {
        $rules = [self::rule(0, 60, 'up')];
        $this->assertSame(-5, TimeRoundingService::roundDuration(-5, $rules));
    }

    public function testDurationBelowLowestThresholdLeavesValueUnchanged(): void
    {
        // No rule covers [0, 60), so a 30-min visit must not be silently swallowed.
        $rules = [self::rule(60, 30, 'down')];
        $this->assertSame(30, TimeRoundingService::roundDuration(30, $rules));
    }

    // ── User's documented 3-rule example ──────────────────────────────────────

    public function testUserExampleRoundsBelow60Up(): void
    {
        $rules = [
            self::rule(0, 60, 'up'),
            self::rule(60, 60, 'down'),
            self::rule(70, 0, 'none'),
        ];
        $this->assertSame(60, TimeRoundingService::roundDuration(35, $rules));
        $this->assertSame(60, TimeRoundingService::roundDuration(1, $rules));
        $this->assertSame(60, TimeRoundingService::roundDuration(59, $rules));
    }

    public function testUserExampleRounds60To69Down(): void
    {
        $rules = [
            self::rule(0, 60, 'up'),
            self::rule(60, 60, 'down'),
            self::rule(70, 0, 'none'),
        ];
        $this->assertSame(60, TimeRoundingService::roundDuration(60, $rules));
        $this->assertSame(60, TimeRoundingService::roundDuration(65, $rules));
        $this->assertSame(60, TimeRoundingService::roundDuration(69, $rules));
    }

    public function testUserExampleAbove70Unrounded(): void
    {
        $rules = [
            self::rule(0, 60, 'up'),
            self::rule(60, 60, 'down'),
            self::rule(70, 0, 'none'),
        ];
        $this->assertSame(70, TimeRoundingService::roundDuration(70, $rules));
        $this->assertSame(90, TimeRoundingService::roundDuration(90, $rules));
        $this->assertSame(123, TimeRoundingService::roundDuration(123, $rules));
    }

    // ── Direction × interval matrix ───────────────────────────────────────────

    /**
     * @dataProvider directionMatrixProvider
     */
    public function testDirectionMatrix(int $minutes, int $interval, string $direction, int $expected): void
    {
        $rules = [self::rule(0, $interval, $direction)];
        $this->assertSame($expected, TimeRoundingService::roundDuration($minutes, $rules));
    }

    public static function directionMatrixProvider(): array
    {
        return [
            'up to 5'      => [3, 5, 'up', 5],
            'up to 5 keeps multiple' => [10, 5, 'up', 10],
            'up to 10'     => [11, 10, 'up', 20],
            'up to 15'     => [16, 15, 'up', 30],
            'up to 30'     => [31, 30, 'up', 60],
            'up to 60'     => [61, 60, 'up', 120],
            'down to 5'    => [9, 5, 'down', 5],
            'down to 10'   => [19, 10, 'down', 10],
            'down to 15'   => [44, 15, 'down', 30],
            'down to 30'   => [59, 30, 'down', 30],
            'down to 60'   => [119, 60, 'down', 60],
            'nearest 5 up'   => [3, 5, 'nearest', 5],
            'nearest 5 dn'   => [2, 5, 'nearest', 0],
            'nearest 10 mid' => [15, 10, 'nearest', 20],
            'nearest 15 dn'  => [22, 15, 'nearest', 15],
            'nearest 15 up'  => [23, 15, 'nearest', 30],
            'nearest 30 dn'  => [44, 30, 'nearest', 30],
            'nearest 30 up'  => [45, 30, 'nearest', 60],
            'nearest 60 dn'  => [89, 60, 'nearest', 60],
            'nearest 60 up'  => [90, 60, 'nearest', 120],
        ];
    }

    // ── "none" direction & interval=0 are no-ops ──────────────────────────────

    public function testDirectionNoneLeavesValueUntouched(): void
    {
        $rules = [self::rule(0, 0, 'none')];
        $this->assertSame(47, TimeRoundingService::roundDuration(47, $rules));
    }

    public function testIntervalZeroLeavesValueUntouchedEvenWithBogusDirection(): void
    {
        // Defensive: even if a stale payload pairs interval=0 with direction='up',
        // we must return the input unchanged rather than divide by zero.
        $rules = [self::rule(0, 0, 'up')];
        $this->assertSame(42, TimeRoundingService::roundDuration(42, $rules));
    }

    // ── Threshold boundaries & ordering ───────────────────────────────────────

    public function testExactThresholdMatchesItsRule(): void
    {
        $rules = [
            self::rule(0, 60, 'up'),
            self::rule(60, 60, 'down'),
        ];
        // 60 is the exact lower bound of the second rule, so 'down' applies.
        $this->assertSame(60, TimeRoundingService::roundDuration(60, $rules));
    }

    public function testUnsortedRulesAreSortedBeforeMatching(): void
    {
        // Same data as user example but passed in reverse — the service must sort
        // defensively so a misbehaving caller doesn't yield wrong rounding.
        $rules = [
            self::rule(70, 0, 'none'),
            self::rule(60, 60, 'down'),
            self::rule(0, 60, 'up'),
        ];
        $this->assertSame(60, TimeRoundingService::roundDuration(35, $rules));
        $this->assertSame(60, TimeRoundingService::roundDuration(65, $rules));
        $this->assertSame(90, TimeRoundingService::roundDuration(90, $rules));
    }

    public function testLastRuleExtendsToInfinity(): void
    {
        $rules = [
            self::rule(0, 60, 'down'),
            self::rule(120, 30, 'up'),
        ];
        // 9999 falls into [120, ∞) → 30-step up → 10020
        $this->assertSame(10020, TimeRoundingService::roundDuration(9999, $rules));
    }

    public function testUnknownDirectionFallsBackToInputUnchanged(): void
    {
        $rules = [['threshold_minutes' => 0, 'interval_minutes' => 30, 'direction' => 'sideways']];
        $this->assertSame(42, TimeRoundingService::roundDuration(42, $rules));
    }
}
