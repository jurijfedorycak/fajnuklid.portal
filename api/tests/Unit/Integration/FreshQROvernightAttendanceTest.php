<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Helpers\FreshQRClient;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyRoundingRuleRepository;
use App\Repositories\EmployeeRepository;
use App\Services\FreshQRService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Integration coverage of the reported overnight-attendance bug: a cleaning
 * that starts one evening and is scanned out in the early morning of the NEXT
 * day. The scan-out was followed by an accidental double-scan, leaving a
 * closed zero-length pair on the follow-up day — which the portal then showed
 * as an all-day "Právě probíhá" plus a phantom visit.
 *
 * The raw wire rows below are the real production incident (night of
 * 2026-07-11 → 2026-07-12, anonymised employee ids). The tests drive them
 * through the REAL portal pipeline — FreshQRClient's reshape, the stale-scan
 * filter and the calendar builder — only the HTTP transport is elided.
 */
class FreshQROvernightAttendanceTest extends TestCase
{
    private const ICO = '19469063';

    /**
     * @return list<array<string,mixed>>  attendance-raw wire rows for the incident night
     */
    private static function incidentWireRows(string $startDay, string $nextDay): array
    {
        return [
            [
                'Id' => 979554,
                'CompanyEmployeeId' => 4,
                'TimeFrom' => "$startDay 07:01:52",
                'TimeTo' => "$startDay 10:10:03",
                'DurationMinutes' => 188,
                'TaskName1' => 'Padel Powers ' . self::ICO,
            ],
            [
                // The reported overnight visit: starts in the evening, scan-out
                // shortly after midnight on the following day.
                'Id' => 980470,
                'CompanyEmployeeId' => 7,
                'TimeFrom' => "$startDay 21:06:04",
                'TimeTo' => "$nextDay 02:08:35",
                'DurationMinutes' => 302,
                'TaskName1' => 'Padel Powers ' . self::ICO,
            ],
            [
                // Double-scan ghost seconds after the scan-out: a CLOSED
                // zero-length pair anchored to the follow-up day.
                'Id' => 980511,
                'CompanyEmployeeId' => 7,
                'TimeFrom' => "$nextDay 02:08:46",
                'TimeTo' => "$nextDay 02:08:54",
                'DurationMinutes' => 0,
                'TaskName1' => 'Padel Powers ' . self::ICO,
            ],
        ];
    }

    /**
     * Run raw wire rows through the real FreshQRClient reshape (the portal's
     * attendance-raw ingestion), exactly as getAttendanceRawForRange does.
     *
     * @param  list<array<string,mixed>> $wireRows
     * @return list<array<string,mixed>>
     */
    private static function reshapeAll(array $wireRows): array
    {
        $reshape = new ReflectionMethod(FreshQRClient::class, 'reshapeRawAttendanceRecord');
        $reshape->setAccessible(true);

        $idToPersonal = [4 => 'EMP004', 7 => 'EMP007'];
        $records = [];
        foreach ($wireRows as $row) {
            $reshaped = $reshape->invoke(null, $row, $idToPersonal);
            if ($reshaped !== null) {
                $records[] = $reshaped;
            }
        }
        return $records;
    }

    public function testOvernightVisitClosedAtNightNeverFlagsTheFollowUpDayAsOngoing(): void
    {
        $records = self::reshapeAll(self::incidentWireRows('2026-07-11', '2026-07-12'));

        // The ghost pair must not survive ingestion; both genuine visits do.
        $this->assertCount(2, $records);

        $dropStale = new ReflectionMethod(FreshQRService::class, 'dropStalePastOpenScans');
        $dropStale->setAccessible(true);

        $tz = new \DateTimeZone('Europe/Prague');
        // The client kept seeing "Právě probíhá" throughout the follow-up day;
        // assert the pipeline output at several instants of that day and once
        // the day after.
        foreach (['2026-07-12 03:00', '2026-07-12 10:00', '2026-07-12 16:00', '2026-07-13 09:00'] as $instant) {
            $now = new \DateTimeImmutable($instant, $tz);
            $today = $now->format('Y-m-d');

            $filtered = $dropStale->invoke(null, $records, $today, $now);
            $days = FreshQRService::buildCleaningDays(
                $filtered,
                [self::ICO => 'detailed'],
                ['EMP004' => 0, 'EMP007' => 0],
                ['EMP004' => 'Testovací A.', 'EMP007' => 'Testovací B.'],
                $today,
                [],
                $now
            );

            $byDate = array_column($days, null, 'date');

            $startDay = $byDate['2026-07-11'] ?? null;
            $this->assertNotNull($startDay, "start day missing at $instant");
            $this->assertFalse($startDay['ongoing'], "start day flagged ongoing at $instant");

            $overnight = null;
            foreach ($startDay['cleanings'] as $cleaning) {
                if ($cleaning['startTime'] === '21:06') {
                    $overnight = $cleaning;
                }
            }
            $this->assertNotNull($overnight, "overnight visit missing at $instant");
            $this->assertFalse($overnight['ongoing']);
            $this->assertSame('02:08', $overnight['endTime']);
            $this->assertSame(302, $overnight['rawMinutes']);
            // End reads before start — the FE's signal to render "+1 day".
            $this->assertLessThan($overnight['startTime'], $overnight['endTime']);

            // The reported symptom: the follow-up day showed "Právě probíhá"
            // all day plus a phantom 02:08 visit. Neither may reappear.
            $this->assertArrayNotHasKey('2026-07-12', $byDate, "phantom visit day at $instant");
            foreach ($days as $day) {
                $this->assertFalse($day['ongoing'], "day {$day['date']} flagged ongoing at $instant");
            }
        }
    }

    public function testOvernightVisitRendersCorrectlyThroughServiceEntryPoint(): void
    {
        // Same incident driven through the public service path the controllers
        // call. getCleaningDaysForCompanies reads the real clock internally, so
        // the incident is re-anchored to yesterday → today, keeping the
        // "closed overnight visit viewed on the follow-up day" shape.
        $tz = new \DateTimeZone('Europe/Prague');
        $today = new \DateTimeImmutable('today', $tz);
        $yesterday = $today->modify('-1 day');
        $wireRows = self::incidentWireRows($yesterday->format('Y-m-d'), $today->format('Y-m-d'));

        $clientMock = $this->createMock(FreshQRClient::class);
        $clientMock->method('isConfigured')->willReturn(true);
        $clientMock->method('getAttendanceRawForRange')->willReturnCallback(
            static fn () => self::reshapeAll($wireRows)
        );

        $employeeRepoMock = $this->createMock(EmployeeRepository::class);
        $employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP004', 'EMP007']);
        $employeeRepoMock->method('findDisplayNamesByPersonalIds')->willReturn([
            'EMP004' => 'Testovací A.',
            'EMP007' => 'Testovací B.',
        ]);

        $roundingRuleRepoMock = $this->createMock(CompanyRoundingRuleRepository::class);
        $roundingRuleRepoMock->method('findByCompanyIds')->willReturn([]);

        $reflection = new ReflectionClass(FreshQRService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'client' => $clientMock,
            'companyRepo' => $this->createMock(CompanyRepository::class),
            'employeeRepo' => $employeeRepoMock,
            'roundingRuleRepo' => $roundingRuleRepoMock,
        ] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        $companies = [[
            'id' => 7,
            'name' => 'Padel Powers',
            'registration_number' => self::ICO,
            'freshqr_mode' => 'detailed',
        ]];

        $result = $service->getCleaningDaysForCompanies(
            $companies,
            (int) $yesterday->format('Y'),
            (int) $yesterday->format('n')
        );

        $this->assertTrue($result['active']);
        $this->assertNull($result['error']);

        $byDate = array_column($result['cleaningDays'], null, 'date');

        $startDay = $byDate[$yesterday->format('Y-m-d')] ?? null;
        $this->assertNotNull($startDay, 'overnight visit must appear on its start day');
        $this->assertFalse($startDay['ongoing']);
        $endTimes = array_column($startDay['cleanings'], 'endTime', 'startTime');
        $this->assertSame('02:08', $endTimes['21:06'] ?? null);

        // The follow-up day carries no phantom visit and nothing is ongoing.
        $this->assertArrayNotHasKey($today->format('Y-m-d'), $byDate);
        foreach ($result['cleaningDays'] as $day) {
            $this->assertFalse($day['ongoing'], "day {$day['date']} must not be ongoing");
        }
    }
}
