<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Helpers\FreshQRClient;
use App\Repositories\CompanyRepository;
use App\Repositories\EmployeeRepository;
use App\Services\FreshQRService;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Tests\TestCase;

class FreshQRServiceTest extends TestCase
{
    private FreshQRService $service;
    private MockObject&FreshQRClient $clientMock;
    private MockObject&CompanyRepository $companyRepoMock;
    private MockObject&EmployeeRepository $employeeRepoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(FreshQRClient::class);
        $this->companyRepoMock = $this->createMock(CompanyRepository::class);
        $this->employeeRepoMock = $this->createMock(EmployeeRepository::class);

        $reflection = new ReflectionClass(FreshQRService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'client' => $this->clientMock,
            'companyRepo' => $this->companyRepoMock,
            'employeeRepo' => $this->employeeRepoMock,
        ] as $name => $value) {
            $prop = $reflection->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($this->service, $value);
        }
    }

    /**
     * Most legacy tests assume "all IČOs are equally exposed". They predate the
     * per-IČO mode switch — this helper translates the flat IČO list they care
     * about into the new mode map shape with a uniform mode (defaults to basic
     * because that's the closest analogue of the old all-or-nothing behaviour).
     *
     * @param list<string>      $icos
     * @param array<string,int> $allowed
     */
    private static function callBuild(
        array $records,
        array $icos,
        array $allowed,
        string $today,
        string $mode = 'basic',
        array $displayNames = []
    ): array {
        $modeMap = [];
        foreach ($icos as $ico) {
            $modeMap[$ico] = $mode;
        }
        return FreshQRService::buildCleaningDays($records, $modeMap, $allowed, $displayNames, $today);
    }

    // extractIcos

    public function testExtractIcosReturnsEmptyForEmptyInput(): void
    {
        $this->assertEquals([], FreshQRService::extractIcos([]));
    }

    public function testExtractIcosCollectsRegistrationNumbers(): void
    {
        $companies = [
            ['registration_number' => '12345678'],
            ['registration_number' => '87654321'],
        ];

        $this->assertEquals(['12345678', '87654321'], FreshQRService::extractIcos($companies));
    }

    public function testExtractIcosDropsEmptyAndMissingIcos(): void
    {
        $companies = [
            ['registration_number' => '12345678'],
            ['registration_number' => ''],
            ['registration_number' => '  '],
            ['name' => 'Company without IČO'],
            ['registration_number' => '87654321'],
        ];

        $this->assertEquals(['12345678', '87654321'], FreshQRService::extractIcos($companies));
    }

    public function testExtractIcosDropsNonDigitValues(): void
    {
        $companies = [
            ['registration_number' => 'ABC12345'],
            ['registration_number' => '1234-5678'],
            ['registration_number' => '12345678'],
        ];

        $this->assertEquals(['12345678'], FreshQRService::extractIcos($companies));
    }

    public function testExtractIcosDropsTooShortAndTooLongIcos(): void
    {
        $companies = [
            ['registration_number' => '123'],
            ['registration_number' => '12345678901'],
            ['registration_number' => '12345678'],
        ];

        $this->assertEquals(['12345678'], FreshQRService::extractIcos($companies));
    }

    public function testExtractIcosDeduplicates(): void
    {
        $companies = [
            ['registration_number' => '12345678'],
            ['registration_number' => '12345678'],
        ];

        $this->assertEquals(['12345678'], FreshQRService::extractIcos($companies));
    }

    public function testExtractIcosTrimsWhitespace(): void
    {
        $companies = [
            ['registration_number' => '  12345678  '],
        ];

        $this->assertEquals(['12345678'], FreshQRService::extractIcos($companies));
    }

    // buildModeByIcoMap

    public function testBuildModeByIcoMapKeepsBasicAndDetailedAndDropsOff(): void
    {
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'basic'],
            ['registration_number' => '22222222', 'freshqr_mode' => 'detailed'],
            ['registration_number' => '33333333', 'freshqr_mode' => 'off'],
            ['registration_number' => '44444444'], // missing mode → treated as off
            ['registration_number' => '55555555', 'freshqr_mode' => null],
            ['registration_number' => '66666666', 'freshqr_mode' => 'gibberish'],
        ];

        $this->assertEquals(
            ['11111111' => 'basic', '22222222' => 'detailed'],
            FreshQRService::buildModeByIcoMap($companies)
        );
    }

    public function testBuildModeByIcoMapNormalisesMixedCaseModeStrings(): void
    {
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'BASIC'],
            ['registration_number' => '22222222', 'freshqr_mode' => '  Detailed '],
        ];

        $this->assertEquals(
            ['11111111' => 'basic', '22222222' => 'detailed'],
            FreshQRService::buildModeByIcoMap($companies)
        );
    }

    public function testBuildModeByIcoMapPrefersDetailedWhenSameIcoAppearsTwice(): void
    {
        // Defensive — registration_number is UK in DB so duplicates shouldn't
        // exist, but if they ever do, the more permissive mode wins.
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'detailed'],
            ['registration_number' => '11111111', 'freshqr_mode' => 'basic'],
        ];

        $this->assertEquals(
            ['11111111' => 'detailed'],
            FreshQRService::buildModeByIcoMap($companies)
        );
    }

    // buildCleaningDays — mapping rules (legacy basic-mode equivalents)

    public function testBuildCleaningDaysReturnsEmptyWhenNoRecords(): void
    {
        $result = self::callBuild([], ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysMatchesIcoAsSubstring(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => 'ACME 12345678 - Main Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-10', $result[0]['date']);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysSkipsWhenIcoNotInProjectName(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => 'Some Other Project'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysSkipsWhenPersonalNumberNotInAllowList(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'STRANGER'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysSkipsWhenPersonalNumberNull(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => null],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysSkipsWhenPersonalNumberEmptyString(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => ''],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysSkipsWhenDateMissing(): void
    {
        $records = [
            [
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysRejectsMalformedDateStrings(): void
    {
        $records = [
            ['date' => 'not-a-date', 'project' => ['name' => '12345678'], 'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-13-01', 'project' => ['name' => '12345678'], 'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-02-30', 'project' => ['name' => '12345678'], 'employee' => ['personal_number' => 'EMP001']],
            ['date' => '26-04-10',   'project' => ['name' => '12345678'], 'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-10 ', 'project' => ['name' => '12345678'], 'employee' => ['personal_number' => 'EMP001']],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysWontMatchIcoSubstringAcrossDigitBoundary(): void
    {
        // 12345678 must NOT match inside 123456789 (a 9-digit string where 12345678
        // happens to be a prefix) — the regex guard requires non-digit boundaries.
        $records = [
            ['date' => '2026-04-10', 'project' => ['name' => 'Customer 123456789 - HQ'], 'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-11', 'project' => ['name' => 'Customer 812345678 - HQ'], 'employee' => ['personal_number' => 'EMP001']],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysMatchesIcoAtStartMiddleAndEndOfName(): void
    {
        $records = [
            ['date' => '2026-04-10', 'project' => ['name' => '12345678 Main'],       'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-11', 'project' => ['name' => 'Office 12345678 HQ'],  'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-12', 'project' => ['name' => 'Office 12345678'],     'employee' => ['personal_number' => 'EMP001']],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(3, $result);
    }

    public function testBuildCleaningDaysFlagsTodayAsOngoing(): void
    {
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysDoesNotFlagOngoingWhenEmployeeMovedToAnotherProject(): void
    {
        // Bug scenario: employee cleaned client A (matching IČO) this morning,
        // then scanned in at a different client's project. Client A must not
        // still see "ongoing" — the cleaning is finished.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Customer 12345678 HQ'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '07:00:00',
                'last_scan_time' => '10:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '11:30:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-21', $result[0]['date']);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenEmployeeIsStillAtMatchingProject(): void
    {
        // Mirror of the previous test: if the latest scan is on the matching
        // project, it IS ongoing.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '07:00:00',
                'last_scan_time' => '10:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Customer 12345678 HQ'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '11:30:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenAnotherEmployeeIsStillAtProjectEvenIfOneMoved(): void
    {
        // Two employees on the matching project today. EMP001 moved on, EMP002
        // is still there → the day is still ongoing overall.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '09:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '11:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'last_scan_time' => '10:30:00',
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0, 'EMP002' => 0],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysDoesNotFlagOngoingForPastDateEvenIfLatestScan(): void
    {
        // Even the most-recent scan on a past date isn't "ongoing" — the whole
        // "ongoing" concept only applies to today.
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '15:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysUsesFirstScanTimeWhenLastScanTimeMissing(): void
    {
        // If last_scan_time is absent, fall back to first_scan_time so an
        // arrival-only record can still be compared against records that have
        // both times.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '12:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysRecordWithScanTimeBeatsRecordWithNoneForSameEmployee(): void
    {
        // Asymmetric data: the matching-project record has no scan time, the
        // later non-matching record has one. The non-matching record wins the
        // "latest" comparison and finishes the matching one.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '11:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysHandlesIsoTimestampScanTimes(): void
    {
        // Defensive: if FreshQR ever returns full ISO timestamps instead of
        // HH:MM:SS, lexical comparison still sorts them chronologically as
        // long as the format is consistent across records.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '2026-04-21T09:00:00Z',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '2026-04-21T12:00:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysOtherEmployeeElsewhereDoesNotAffectThisEmployeesStatus(): void
    {
        // EMP001 is still at the matching project today. EMP002 moved on from
        // a different project. EMP002's move must not finish EMP001's record.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'last_scan_time' => '09:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'last_scan_time' => '11:00:00',
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0, 'EMP002' => 0],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysDeduplicatesSameDayMultipleEmployees(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0, 'EMP002' => 0],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-10', $result[0]['date']);
    }

    public function testBuildCleaningDaysSortsByDateAscending(): void
    {
        $records = [
            [
                'date' => '2026-04-15',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
            [
                'date' => '2026-04-05',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals(['2026-04-05', '2026-04-10', '2026-04-15'], array_column($result, 'date'));
    }

    public function testBuildCleaningDaysMatchesAnyOfMultipleIcos(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => 'Something 87654321'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
            [
                'date' => '2026-04-11',
                'project' => ['name' => 'Something else'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678', '87654321'],
            ['EMP001' => 0],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-10', $result[0]['date']);
    }

    public function testBuildCleaningDaysBasicModeNeverLeaksEmployeeOrTimes(): void
    {
        // Privacy guarantee: in basic mode the cleanings array is always present
        // (stable shape) but always empty — no employee names, no scan times,
        // no notes ever cross the wire for a basic-mode IČO.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['id' => 9999, 'first_name' => 'Jurij', 'personal_number' => 'EMP001'],
                'first_scan_time' => '07:30:00',
                'last_scan_time' => '10:45:00',
                'worked_hours' => 3.25,
                'note' => 'Should never reach the client',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals(['date', 'ongoing', 'cleanings'], array_keys($result[0]));
        $this->assertSame([], $result[0]['cleanings']);
    }

    // buildCleaningDays — Detailed mode

    public function testBuildCleaningDaysDetailedModeExposesEmployeeAndTimes(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.']
        );

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]['cleanings']);
        $cleaning = $result[0]['cleanings'][0];
        $this->assertEquals('Anna N.', $cleaning['employee']);
        $this->assertEquals('08:00', $cleaning['startTime']);
        $this->assertEquals('11:30', $cleaning['endTime']);
        $this->assertNull($cleaning['note']);
        $this->assertEquals('12345678', $cleaning['ico']);
    }

    public function testBuildCleaningDaysDetailedModeFallsBackToPersonalIdWhenNameMissing(): void
    {
        // Personal_id is allow-listed but no display name was supplied —
        // surface the personal_id itself rather than blanking out the row.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP999'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP999' => 0],
            '2026-04-21',
            'detailed',
            [] // no display names
        );

        $this->assertEquals('EMP999', $result[0]['cleanings'][0]['employee']);
    }

    public function testBuildCleaningDaysDetailedModeReturnsNullEndTimeWhenStillOnSite(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '09:00:00',
                'last_scan_time' => '09:00:00', // same as first → still on-site
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '10:00:00',
                // last_scan_time absent
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0, 'EMP002' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.', 'EMP002' => 'Petr K.']
        );

        $this->assertCount(2, $result[0]['cleanings']);
        // Sorted by startTime: 09:00 first, then 10:00
        $this->assertEquals('09:00', $result[0]['cleanings'][0]['startTime']);
        $this->assertNull($result[0]['cleanings'][0]['endTime']);
        $this->assertNull($result[0]['cleanings'][1]['endTime']);
    }

    public function testBuildCleaningDaysDetailedModeSortsCleaningsChronologically(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '13:00:00',
                'last_scan_time' => '15:00:00',
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0, 'EMP002' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.', 'EMP002' => 'Petr K.']
        );

        $this->assertEquals(['08:00', '13:00'], array_column($result[0]['cleanings'], 'startTime'));
    }

    public function testBuildCleaningDaysDetailedModeMergesNotesArrayWithBlankLineDelimiter(): void
    {
        // FreshQR will deliver per-cleaning notes as a list — surface them merged
        // with a blank-line delimiter so each remark reads as its own paragraph.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
                'notes' => ['Doplněn papír na záchody.', 'Vyměnili jsme rozbitou žárovku v chodbě.'],
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.']
        );

        $this->assertEquals(
            "Doplněn papír na záchody.\n\nVyměnili jsme rozbitou žárovku v chodbě.",
            $result[0]['cleanings'][0]['note']
        );
    }

    public function testBuildCleaningDaysDetailedModeNoteIsNullWhenNotesArrayMissingOrEmpty(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
                // no notes key at all
            ],
            [
                'date' => '2026-04-11',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
                'notes' => [],
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.']
        );

        $this->assertNull($result[0]['cleanings'][0]['note']);
        $this->assertNull($result[1]['cleanings'][0]['note']);
    }

    public function testBuildCleaningDaysDetailedModeDropsEmptyAndNonStringNoteEntries(): void
    {
        // Defensive: FreshQR may include blanks, whitespace-only entries, or
        // misshapen rows in the notes array. Drop them silently and keep the
        // useful ones.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
                'notes' => ['   ', null, 42, ['nested'], 'Skutečná poznámka.', ''],
            ],
        ];

        $result = self::callBuild(
            $records,
            ['12345678'],
            ['EMP001' => 0],
            '2026-04-21',
            'detailed',
            ['EMP001' => 'Anna N.']
        );

        $this->assertEquals('Skutečná poznámka.', $result[0]['cleanings'][0]['note']);
    }

    public function testBuildCleaningDaysMixedModeOnlyDetailedIcosContributeCleanings(): void
    {
        // Two IČOs, same day. The detailed-mode IČO contributes a cleaning
        // entry; the basic-mode IČO contributes the date itself but no
        // cleanings entry.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '11111111 Office'], // basic
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:00:00',
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '22222222 Office'], // detailed
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '13:00:00',
                'last_scan_time' => '15:00:00',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['11111111' => 'basic', '22222222' => 'detailed'],
            ['EMP001' => 0, 'EMP002' => 0],
            ['EMP001' => 'Anna N.', 'EMP002' => 'Petr K.'],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertCount(1, $result[0]['cleanings']);
        $this->assertEquals('22222222', $result[0]['cleanings'][0]['ico']);
        $this->assertEquals('Petr K.', $result[0]['cleanings'][0]['employee']);
    }

    public function testBuildCleaningDaysOffModeIcoIsAbsentFromMapAndContributesNothing(): void
    {
        // Sanity: an off-mode IČO is filtered out of the map upstream by
        // buildModeByIcoMap. If it never reaches buildCleaningDays, records
        // for that IČO must be invisible.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            [], // no IČOs in the map
            ['EMP001' => 0],
            [],
            '2026-04-21'
        );

        $this->assertEquals([], $result);
    }

    // formatScanTimeToHm

    public function testFormatScanTimeToHmHandlesAllSupportedShapes(): void
    {
        $this->assertEquals('08:30', FreshQRService::formatScanTimeToHm('08:30:00'));
        $this->assertEquals('08:30', FreshQRService::formatScanTimeToHm('08:30'));
        $this->assertEquals('09:00', FreshQRService::formatScanTimeToHm('2026-04-21T09:00:00Z'));
        $this->assertEquals('14:25', FreshQRService::formatScanTimeToHm('2026-04-21T14:25:00+02:00'));
    }

    public function testFormatScanTimeToHmReturnsNullForUnparseableInput(): void
    {
        $this->assertNull(FreshQRService::formatScanTimeToHm(''));
        $this->assertNull(FreshQRService::formatScanTimeToHm('not-a-time'));
        $this->assertNull(FreshQRService::formatScanTimeToHm('25:99'));
    }

    // isConfigured / getLastError / getCleaningDaysForUser flows

    public function testIsConfiguredDelegatesToClient(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);

        $this->assertTrue($this->service->isConfigured());
    }

    public function testGetCleaningDaysForUserReturnsInactiveWhenNotConfigured(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(false);
        $this->companyRepoMock->expects($this->never())->method('findByUserId');

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertFalse($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
        $this->assertNull($result['error']);
    }

    public function testGetCleaningDaysForUserReturnsInactiveWhenUserHasNoIcos(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([]);

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertFalse($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
        $this->assertNull($result['error']);
    }

    public function testGetCleaningDaysForUserReturnsInactiveWhenAllIcosAreOff(): void
    {
        // User has IČOs but the admin set every one to off → no calendar.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'off'],
        ]);

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertFalse($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
    }

    public function testGetCleaningDaysForUserReturnsActiveWithErrorWhenApiCallFails(): void
    {
        // Configured + has IČOs + API failed → keep calendar visible but surface
        // an error string, so the FE can show a banner rather than onboarding.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->clientMock->method('getProjectReports')->willReturn(null);

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertTrue($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
        $this->assertIsString($result['error']);
        $this->assertNotEmpty($result['error']);
    }

    public function testGetCleaningDaysForUserReturnsActiveWithMatchedRecords(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->clientMock->method('getProjectReports')->willReturn([
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Main Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertEquals('2026-04-10', $result['cleaningDays'][0]['date']);
        $this->assertNull($result['error']);
    }

    public function testGetCleaningDaysForUserBasicModeNeverEmitsCleaningEntries(): void
    {
        // End-to-end privacy guarantee: even if FreshQR returns a record with
        // employee identity + scan times + notes for a basic-mode IČO, the
        // service-level output for that day must contain an EMPTY cleanings
        // array. No employee, no times, no notes cross the wire.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->clientMock->method('getProjectReports')->willReturn([
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['first_name' => 'Anna', 'personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
                'notes' => ['Sensitive note that must not leak.'],
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        // Display-name lookup must not be called for basic-only setups.
        $this->employeeRepoMock->expects($this->never())->method('findDisplayNamesByPersonalIds');

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertCount(1, $result['cleaningDays']);
        $day = $result['cleaningDays'][0];
        $this->assertSame('2026-04-10', $day['date']);
        $this->assertSame([], $day['cleanings'], 'Basic-mode IČO must produce empty cleanings[]');
        $serialised = json_encode($result);
        $this->assertStringNotContainsString('Anna', $serialised);
        $this->assertStringNotContainsString('08:00', $serialised);
        $this->assertStringNotContainsString('Sensitive note', $serialised);
    }

    public function testGetCleaningDaysForUserSkipsDisplayNameLookupWhenNoDetailedIcos(): void
    {
        // Detailed-mode lookup is the only consumer of display names. Basic-only
        // setups must skip the query so we don't waste a round-trip.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->clientMock->method('getProjectReports')->willReturn([]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        $this->employeeRepoMock->expects($this->never())->method('findDisplayNamesByPersonalIds');

        $this->service->getCleaningDaysForUser(1, 2026, 4);
    }

    public function testGetCleaningDaysForUserReturnsActiveEmptyWhenNoMatches(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->clientMock->method('getProjectReports')->willReturn([
            [
                'date' => '2026-04-10',
                'project' => ['name' => 'Unrelated project'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForUser(1, 2026, 4);

        $this->assertTrue($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
        $this->assertNull($result['error']);
    }

    public function testGetCleaningDaysForUserResetsLastErrorBeforeApiCall(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678', 'freshqr_mode' => 'basic'],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn([]);
        $this->clientMock->method('getProjectReports')->willReturn([]);

        // resetLastError must be called before the API call so stale errors
        // from a previous call don't leak into this one.
        $this->clientMock->expects($this->once())->method('resetLastError');

        $this->service->getCleaningDaysForUser(1, 2026, 4);
    }

    public function testGetLastErrorDelegatesToClient(): void
    {
        $sampleError = ['context' => 'API call', 'http_code' => 500];
        $this->clientMock->method('getLastError')->willReturn($sampleError);

        $this->assertEquals($sampleError, $this->service->getLastError());
    }
}
