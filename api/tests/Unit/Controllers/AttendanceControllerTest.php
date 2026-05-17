<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AttendanceController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit coverage for the private redaction helper on AttendanceController.
 *
 * Why this lives outside the controller's HTTP path: the helper is the only
 * piece of role-aware display logic on the boundary, and reflection-based
 * tests pin its contract without needing a full request/response harness.
 * The orchestration (which view picks which $clientView flag) is exercised
 * end-to-end by the FreshQRService tests + browser verification.
 */
class AttendanceControllerTest extends TestCase
{
    private static function applyRedactions(array $cleaningDays, bool $clientView): array
    {
        $method = new ReflectionMethod(AttendanceController::class, 'applyRoundingRedactions');
        $method->setAccessible(true);
        return $method->invoke(null, $cleaningDays, $clientView);
    }

    private static function buildDay(array $cleaningOverrides): array
    {
        return [[
            'date' => '2026-05-13',
            'ongoing' => false,
            'cleanings' => [array_merge([
                'employee'         => 'Anna N.',
                'startTime'        => '19:08',
                'endTime'          => '21:25',
                'note'             => null,
                'ico'              => '12345678',
                'rawMinutes'       => 137,
                'roundedMinutes'   => 150,
                'roundedEndTime'   => '21:38',
                'hasRoundingRules' => true,
                'ongoing'          => false,
            ], $cleaningOverrides)],
        ]];
    }

    public function testClientViewSwapsEndTimeForRoundedEndTime(): void
    {
        $result = self::applyRedactions(self::buildDay([]), true);
        $cleaning = $result[0]['cleanings'][0];

        $this->assertSame('19:08', $cleaning['startTime'], 'startTime stays raw — the cleaner\'s arrival is a stable anchor');
        $this->assertSame('21:38', $cleaning['endTime'], 'endTime is replaced with the rounded value');
        $this->assertNull($cleaning['rawMinutes'], 'rawMinutes is hidden from clients');
        $this->assertSame(150, $cleaning['roundedMinutes']);
    }

    public function testAdminViewKeepsRawTimesAndDuration(): void
    {
        $result = self::applyRedactions(self::buildDay([]), false);
        $cleaning = $result[0]['cleanings'][0];

        $this->assertSame('19:08', $cleaning['startTime']);
        $this->assertSame('21:25', $cleaning['endTime'], 'Admin keeps the raw end-time for audit');
        $this->assertSame(137, $cleaning['rawMinutes']);
        $this->assertSame(150, $cleaning['roundedMinutes']);
    }

    public function testOngoingWithRulesDropsStartTimeForBothRoles(): void
    {
        $ongoing = self::buildDay([
            'ongoing' => true,
            'endTime' => null,
            'rawMinutes' => null,
            'roundedMinutes' => null,
            'roundedEndTime' => null,
        ]);

        $client = self::applyRedactions($ongoing, true);
        $admin = self::applyRedactions($ongoing, false);

        $this->assertNull($client[0]['cleanings'][0]['startTime'], 'client view hides startTime on ongoing+rules');
        $this->assertNull($admin[0]['cleanings'][0]['startTime'], 'admin view hides startTime on ongoing+rules');
    }

    public function testOngoingWithoutRulesKeepsStartTime(): void
    {
        $ongoing = self::buildDay([
            'ongoing' => true,
            'endTime' => null,
            'rawMinutes' => null,
            'roundedMinutes' => null,
            'roundedEndTime' => null,
            'hasRoundingRules' => false,
        ]);

        $admin = self::applyRedactions($ongoing, false);

        $this->assertSame('19:08', $admin[0]['cleanings'][0]['startTime'], 'No rules → no hide rule → raw startTime kept');
    }

    public function testInternalFieldsAreAlwaysStripped(): void
    {
        $result = self::applyRedactions(self::buildDay([]), true);
        $cleaning = $result[0]['cleanings'][0];

        $this->assertArrayNotHasKey('roundedEndTime', $cleaning);
        $this->assertArrayNotHasKey('hasRoundingRules', $cleaning);
    }

    public function testEmptyCleaningsListPassesThrough(): void
    {
        // Basic-mode days arrive with an empty cleanings[]. The helper must
        // simply not touch them — no exceptions, no extra keys.
        $day = [['date' => '2026-05-13', 'ongoing' => true, 'cleanings' => []]];
        $result = self::applyRedactions($day, true);
        $this->assertEquals($day, $result);
    }

    public function testRoundedEndTimeFallbackWhenNullKeepsRawEnd(): void
    {
        // Defensive: if the service couldn't compute a rounded end-time (e.g.
        // raw startTime missing), the helper must NOT silently overwrite
        // endTime with null. Keep the raw value so the FE still shows what
        // it can.
        $day = self::buildDay([
            'startTime' => null,
            'endTime' => '21:25',
            'roundedEndTime' => null,
        ]);
        $result = self::applyRedactions($day, true);
        $this->assertSame('21:25', $result[0]['cleanings'][0]['endTime']);
    }
}
