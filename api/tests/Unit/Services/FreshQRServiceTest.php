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

    // isAttendanceEnabledForCompanies

    public function testIsAttendanceEnabledReturnsFalseForNoCompanies(): void
    {
        $this->assertFalse(FreshQRService::isAttendanceEnabledForCompanies([]));
    }

    public function testIsAttendanceEnabledReturnsFalseWhenAllIcosOff(): void
    {
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'off'],
            ['registration_number' => '22222222'], // missing mode → off
            ['registration_number' => '33333333', 'freshqr_mode' => null],
        ];

        $this->assertFalse(FreshQRService::isAttendanceEnabledForCompanies($companies));
    }

    public function testIsAttendanceEnabledReturnsTrueWhenAnyIcoBasic(): void
    {
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'off'],
            ['registration_number' => '22222222', 'freshqr_mode' => 'basic'],
        ];

        $this->assertTrue(FreshQRService::isAttendanceEnabledForCompanies($companies));
    }

    public function testIsAttendanceEnabledReturnsTrueWhenAnyIcoDetailed(): void
    {
        $companies = [
            ['registration_number' => '11111111', 'freshqr_mode' => 'detailed'],
        ];

        $this->assertTrue(FreshQRService::isAttendanceEnabledForCompanies($companies));
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

    public function testBuildCleaningDaysDoesNotFlagOngoingWhenCleanerAlreadyScannedOutHere(): void
    {
        // Previously buggy: a single today record where first_scan != last_scan
        // (cleaner arrived and left at this project, no other activity today)
        // was still marked ongoing because it was the employee's latest scan.
        // The fix recognises a scan-out at the same project as "finished here".
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:00:00', // scanned out
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenSingleScanWithNoScanOutToday(): void
    {
        // Mirror of the previous test: a single-scan record (first present, last
        // missing OR last == first) on today's date is still on-site — the
        // cleaner hasn't scanned out yet.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '08:00:00', // same as first → still on-site
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenOnlyFirstScanRecordedToday(): void
    {
        // Arrival-only record (no last_scan_time at all) on today's date is also
        // ongoing — the cleaner is on-site and hasn't been scanned out anywhere.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysDoesNotFlagOngoingWhenScannedOutAtThisProject(): void
    {
        // The matching record has both scan times set to different values →
        // the cleaner explicitly scanned out at this project, so the cleaning
        // is finished even though it's today.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Customer 12345678 HQ'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '07:00:00',
                'last_scan_time' => '10:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-21', $result[0]['date']);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenSingleScanAtMatchingProject(): void
    {
        // Single-scan record at the matching project: first == last → FreshQR's
        // "still on-site" sentinel → TimeTo is effectively null → ongoing.
        $records = [
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

    public function testBuildCleaningDaysFlagsOngoingWhenAtLeastOneEmployeeIsStillOnSite(): void
    {
        // Two employees today at the matching project. EMP001 scanned out
        // (first ≠ last), EMP002 is still on-site (single scan). The day's
        // aggregate `ongoing` flag is the OR across cleanings, so EMP002 keeps
        // the day open even though EMP001 is finished.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '07:00:00',
                'last_scan_time' => '09:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '10:30:00',
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

    public function testBuildCleaningDaysDoesNotFlagOngoingForPastDate(): void
    {
        // The "ongoing" flag only applies to today — a record on a past day
        // with TimeTo null (single scan or arrival-only) is still finished.
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsArrivalOnlyRecordAsOngoingEvenWhenEmployeeScannedElsewhere(): void
    {
        // Simplified ongoing rule: the portal trusts FreshQR's null TimeTo on
        // THIS record. A later scan-in at another project does NOT close this
        // cleaning — the cleaner may have forgotten to scan out at the matching
        // project, and per FreshQR's data the cleaning is still open. The day
        // therefore stays ongoing until the matching record itself records a
        // scan-out.
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
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsRecordWithNoScanTimesAsOngoingOnToday(): void
    {
        // Edge case: matching record has no scan times at all. Under the
        // simplified rule TimeTo is null → on today's date this counts as
        // ongoing. A later scan at a non-matching project is irrelevant to
        // the matching record's status.
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
        $this->assertTrue($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysHandlesIsoTimestampScanOut(): void
    {
        // Defensive: if FreshQR ever returns full ISO timestamps instead of
        // HH:MM:SS, isScannedOutAtThisProject must still detect a true scan-out
        // (first ≠ last after normalisation). Mixed formats on the same record
        // must collapse to the same HH:MM comparison.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '2026-04-21T11:00:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse(
            $result[0]['ongoing'],
            'ISO-format last_scan_time different from first_scan_time must close the cleaning'
        );
    }

    public function testBuildCleaningDaysDropsRecordWhereFirstScanDateDiffersFromDate(): void
    {
        // Business invariant: Fajnúklid doesn't run overnight cleanings. If
        // FreshQR returns a record whose ISO first_scan_time disagrees with
        // the `date` field (cross-midnight artefact), drop it entirely.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-20T23:50:00Z', // previous day!
                'last_scan_time' => '2026-04-21T00:10:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysDropsRecordWhereLastScanDateDiffersFromDate(): void
    {
        // Same invariant — last_scan_time spilling into the next day means the
        // cleaning crossed midnight, drop it.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-21T23:50:00Z',
                'last_scan_time' => '2026-04-22T00:10:00Z', // next day!
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysAcceptsRecordWithBareHhmmssScanTimes(): void
    {
        // HH:MM:SS scan times carry no date portion, so the single-day check
        // can't compare them — we trust the record's `date` and accept the
        // record. This is the normal FreshQR shape today.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-21', $result[0]['date']);
    }

    public function testBuildCleaningDaysCrossMidnightRecordIsDropped(): void
    {
        // The single-day filter drops anomalous cross-midnight scans before
        // they reach the output. The legitimate same-day record at the matching
        // project still appears and stays ongoing on its own merit (TimeTo null).
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                // Single scan, today's morning — still on-site
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-22T03:00:00Z', // bad data, next day
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['ongoing'], 'Legitimate same-day record is still ongoing');
    }

    public function testBuildCleaningDaysSubMinuteJitterIsTreatedAsSingleScan(): void
    {
        // Regression: computeEndTime used raw string compare; isScannedOutAtThisProject
        // normalises to HH:MM. A record with first='08:00:30' last='08:00:55' could
        // end up with ongoing=true AND a non-null endTime, leaving the FE in a
        // contradictory state. Both predicates now share the same normalisation —
        // sub-minute jitter means "still on-site": ongoing=true, endTime=null.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:30',
                'last_scan_time' => '08:00:55',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21'
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertTrue($cleaning['ongoing'], 'Sub-minute jitter is treated as still on-site');
        $this->assertNull($cleaning['endTime'], 'endTime must agree with ongoing — no contradictory state');
    }

    public function testBuildCleaningDaysScanOutAtMatchingProjectIsFinishedRegardlessOfOtherRecords(): void
    {
        // The matching record was scanned out (first ≠ last). Activity at
        // another project for the same employee is irrelevant under the
        // simplified rule — the matching record's own TimeTo is set, so the
        // cleaning is finished.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:00:00',
            ],
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Other 99999999 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-21T11:30:00Z',
                'last_scan_time' => '2026-04-21T11:30:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
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
        // (stable shape) but always empty — no employee names and no scan times
        // ever cross the wire for a basic-mode IČO.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['id' => 9999, 'first_name' => 'Jurij', 'personal_number' => 'EMP001'],
                'first_scan_time' => '07:30:00',
                'last_scan_time' => '10:45:00',
                'worked_hours' => 3.25,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals(['date', 'ongoing', 'cleanings', 'icos'], array_keys($result[0]));
        $this->assertSame([], $result[0]['cleanings']);
        // The client's own IČO is surfaced (privacy-safe: no times/employees),
        // enabling per-object visit counts for basic mode in the overview.
        $this->assertSame(['12345678'], $result[0]['icos']);
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
        $this->assertArrayNotHasKey('note', $cleaning);
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

    public function testBuildCleaningDaysExposesRawAndRoundedMinutesInDetailedMode(): void
    {
        // FreshQR scans 08:00 → 11:30 → 210 min raw. Rule rounds [0,300) up to 60-min blocks
        // → ceil(210/60)*60 = 240. Both fields must surface to the FE.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21',
            ['12345678' => [
                ['threshold_minutes' => 0, 'interval_minutes' => 60, 'direction' => 'up'],
            ]]
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertSame(210, $cleaning['rawMinutes']);
        $this->assertSame(240, $cleaning['roundedMinutes']);
        // Rounded end-time is anchored on raw start + rounded minutes — 08:00
        // + 240 = 12:00. This is the value the controller swaps in for client
        // views so the displayed range adds up to the billed duration.
        $this->assertSame('12:00', $cleaning['roundedEndTime']);
        $this->assertTrue($cleaning['hasRoundingRules']);
    }

    public function testBuildCleaningDaysExposesHasRoundingRulesEvenWhenRuleIsNoop(): void
    {
        // The flag must reflect "an IČO has rules configured", not "rounding
        // changed the value this time". A direction=none rule still counts —
        // the controller uses the flag to know whether to suppress ongoing
        // TimeFrom, and that decision is about the IČO's config, not about
        // any individual cleaning's outcome.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21',
            ['12345678' => [
                ['threshold_minutes' => 0, 'interval_minutes' => 0, 'direction' => 'none'],
            ]]
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertTrue($cleaning['hasRoundingRules'], 'Configured rule set, even direction=none, must set the flag');
        $this->assertSame(210, $cleaning['rawMinutes']);
        // direction=none → roundedMinutes stays at raw value, no shifted end-time
        $this->assertSame(210, $cleaning['roundedMinutes']);
        $this->assertSame('11:30', $cleaning['roundedEndTime'], 'Shifted end = raw start + raw duration when nothing changed');
    }

    public function testBuildCleaningDaysHasRoundingRulesFalseWhenNoRulesForIco(): void
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

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21'
            // no $roundingRulesByIco — defaults to empty
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertFalse($cleaning['hasRoundingRules']);
        $this->assertNull($cleaning['roundedEndTime']);
    }

    public function testBuildCleaningDaysLeavesRoundedMinutesNullWhenNoRulesForIco(): void
    {
        // No rules for the IČO → roundedMinutes must stay null so the FE keeps
        // showing the raw start/end pair instead of fabricating a billable value.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '11:30:00',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21'
            // no $roundingRulesByIco argument → default []
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertSame(210, $cleaning['rawMinutes']);
        $this->assertNull($cleaning['roundedMinutes']);
    }

    public function testBuildCleaningDaysDetailedModeSurfacesOngoingFlagPerCleaning(): void
    {
        // Detailed-mode payloads carry a per-cleaning `ongoing` flag so the FE
        // doesn't have to infer ongoing from `!endTime` — a signal that fails
        // for past-day single-scan records and for rounding-redacted rows.
        $records = [
            [
                // Today, single scan → ongoing
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
            ],
            [
                // Today, scanned out → not ongoing
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:30:00',
            ],
            [
                // Past day, scanned out → not ongoing
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP003'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:30:00',
            ],
            [
                // Past day, single scan → not ongoing (the bug this flag fixes)
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP004'],
                'first_scan_time' => '08:00:00',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0, 'EMP002' => 0, 'EMP003' => 0, 'EMP004' => 0],
            ['EMP001' => 'Anna N.', 'EMP002' => 'Petr K.', 'EMP003' => 'Eva D.', 'EMP004' => 'Jan M.'],
            '2026-04-21'
        );

        $this->assertCount(2, $result);
        $today = $result[1]; // 2026-04-21 sorts after 2026-04-20
        $past = $result[0];

        $this->assertSame('2026-04-21', $today['date']);
        $byEmployeeToday = [];
        foreach ($today['cleanings'] as $c) {
            $byEmployeeToday[$c['employee']] = $c['ongoing'];
        }
        $this->assertTrue($byEmployeeToday['Anna N.'], 'Single-scan today → ongoing=true');
        $this->assertFalse($byEmployeeToday['Petr K.'], 'Scanned out today → ongoing=false');

        $this->assertSame('2026-04-20', $past['date']);
        $byEmployeePast = [];
        foreach ($past['cleanings'] as $c) {
            $byEmployeePast[$c['employee']] = $c['ongoing'];
        }
        $this->assertFalse($byEmployeePast['Eva D.'], 'Scanned out past day → ongoing=false');
        $this->assertFalse(
            $byEmployeePast['Jan M.'],
            'Single-scan past day → ongoing=false (regression: legacy FE inferred ongoing from null endTime)'
        );
    }

    public function testBuildCleaningDaysLeavesBothMinuteFieldsNullWhenStillOnSite(): void
    {
        // No endTime → no duration → no rounding. Both fields null so the FE
        // shows "Probíhá" instead of a misleading billable count.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '08:00:00', // same as first → still on-site
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21',
            ['12345678' => [
                ['threshold_minutes' => 0, 'interval_minutes' => 60, 'direction' => 'up'],
            ]]
        );

        $cleaning = $result[0]['cleanings'][0];
        $this->assertNull($cleaning['rawMinutes']);
        $this->assertNull($cleaning['roundedMinutes']);
    }

    // computeDurationMinutes

    public function testComputeDurationMinutesReturnsPositiveDifference(): void
    {
        $this->assertSame(210, FreshQRService::computeDurationMinutes('08:00', '11:30'));
        $this->assertSame(1, FreshQRService::computeDurationMinutes('08:00', '08:01'));
    }

    public function testComputeDurationMinutesReturnsNullForMissingOrInvertedTimes(): void
    {
        $this->assertNull(FreshQRService::computeDurationMinutes(null, '11:30'));
        $this->assertNull(FreshQRService::computeDurationMinutes('08:00', null));
        $this->assertNull(FreshQRService::computeDurationMinutes('11:30', '08:00')); // inverted
        $this->assertNull(FreshQRService::computeDurationMinutes('08:00', '08:00')); // zero diff
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

    // shiftTimeByMinutes — display-only HH:mm arithmetic used to compute the
    // billable end-time anchored on the raw scan-in.

    public function testShiftTimeByMinutesAddsForwardWithinSameDay(): void
    {
        $this->assertSame('11:00', FreshQRService::shiftTimeByMinutes('08:00', 180));
        $this->assertSame('08:01', FreshQRService::shiftTimeByMinutes('08:00', 1));
        $this->assertSame('08:00', FreshQRService::shiftTimeByMinutes('08:00', 0));
    }

    public function testShiftTimeByMinutesWrapsAtMidnight(): void
    {
        // Cross-midnight cleanings get dropped upstream, but the helper still
        // needs deterministic wrap-around behaviour so callers can't crash on
        // misconfigured rule sets (e.g. interval larger than a calendar day).
        $this->assertSame('01:00', FreshQRService::shiftTimeByMinutes('23:00', 120));
    }

    public function testShiftTimeByMinutesAcceptsNegativeOffsets(): void
    {
        $this->assertSame('07:30', FreshQRService::shiftTimeByMinutes('08:00', -30));
        $this->assertSame('23:30', FreshQRService::shiftTimeByMinutes('00:00', -30));
    }

    public function testShiftTimeByMinutesReturnsNullForMalformedInput(): void
    {
        $this->assertNull(FreshQRService::shiftTimeByMinutes('25:99', 30));
        $this->assertNull(FreshQRService::shiftTimeByMinutes('not-a-time', 30));
        $this->assertNull(FreshQRService::shiftTimeByMinutes('', 30));
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

    // getCleaningDaysForCompanies — used by the admin "preview as client" flow

    public function testGetCleaningDaysForCompaniesReturnsInactiveWhenNotConfigured(): void
    {
        // The admin preview path must not bypass the FreshQR-not-configured guard;
        // there'd be nothing to render and the FE would silently keep the
        // calendar mounted with stale state.
        $this->clientMock->method('isConfigured')->willReturn(false);
        $this->companyRepoMock->expects($this->never())->method('findByUserId');

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            2026,
            4
        );

        $this->assertFalse($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
        $this->assertNull($result['error']);
    }

    public function testGetCleaningDaysForCompaniesSkipsUserLookup(): void
    {
        // Defining feature of this variant: it must NOT call findByUserId — the
        // caller already has the company list (and intentionally so, since the
        // admin previewing as client has no company_users link to them).
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->expects($this->never())->method('findByUserId');
        $this->clientMock->method('getProjectReports')->willReturn([]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            2026,
            4
        );

        $this->assertTrue($result['active']);
    }

    public function testGetCleaningDaysForCompaniesBuildsCalendarFromGivenCompanies(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Main Office'],
                'employee' => ['personal_number' => 'EMP001'],
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            2026,
            4
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertEquals('2026-04-10', $result['cleaningDays'][0]['date']);
    }

    public function testGetCleaningDaysForCompaniesReturnsInactiveWhenAllIcosOff(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->expects($this->never())->method('getProjectReports');

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'off']],
            2026,
            4
        );

        $this->assertFalse($result['active']);
        $this->assertEquals([], $result['cleaningDays']);
    }

    // Live ongoing-scan augmentation — bridges the gap between FreshQR's cached
    // /v1/reports/projects (excludes null TimeTo) and the live attendance-raw
    // feed used to surface today's in-progress cleanings.

    public function testGetCleaningDaysForCompaniesMergesOngoingScansForCurrentMonth(): void
    {
        // When the user views the current month, the service must call
        // getOngoingProjectReports() and merge its records before computing
        // cleaningDays — otherwise today's open cleanings stay invisible.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        [$y, $m] = array_map('intval', explode('-', $today));

        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([]);
        $this->clientMock->expects($this->once())
            ->method('getOngoingProjectReports')
            ->willReturn([
                [
                    'date' => $today,
                    'project' => ['name' => '12345678 Office'],
                    'employee' => ['personal_number' => 'EMP001'],
                    'first_scan_time' => '18:00:00',
                    'last_scan_time' => null,
                ],
            ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            $y,
            $m
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertSame($today, $result['cleaningDays'][0]['date']);
        $this->assertTrue($result['cleaningDays'][0]['ongoing']);
    }

    public function testGetCleaningDaysForCompaniesSkipsOngoingFetchForPastMonth(): void
    {
        // Past months can't have ongoing activity — calling the live endpoint
        // would just waste an HTTP round-trip. The service must skip it.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        $past = (new \DateTimeImmutable($today . ' -2 months'));
        $pastYear = (int) $past->format('Y');
        $pastMonth = (int) $past->format('n');

        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([]);
        $this->clientMock->expects($this->never())->method('getOngoingProjectReports');
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            $pastYear,
            $pastMonth
        );
    }

    public function testGetCleaningDaysForCompaniesBasicModePrivacyHoldsThroughOngoingMerge(): void
    {
        // Critical privacy guarantee: an ongoing scan merged into the records
        // list for a basic-mode IČO must still produce an EMPTY cleanings[]
        // — basic mode only ever reveals the day-level `ongoing` flag, never
        // employee identity or scan times. Regression risk lives in the new
        // merge path, so guard it explicitly.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        [$y, $m] = array_map('intval', explode('-', $today));

        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([]);
        $this->clientMock->method('getOngoingProjectReports')->willReturn([
            [
                'date' => $today,
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '18:00:00',
                'last_scan_time' => null,
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        // Display-name lookup must not be called for basic-only setups — even
        // when an ongoing record exists, the merge path must not flip the
        // disclosure level.
        $this->employeeRepoMock->expects($this->never())->method('findDisplayNamesByPersonalIds');

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            $y,
            $m
        );

        $this->assertCount(1, $result['cleaningDays']);
        $day = $result['cleaningDays'][0];
        $this->assertSame($today, $day['date']);
        $this->assertTrue($day['ongoing'], 'Day-level ongoing flag still propagates in basic mode');
        $this->assertSame([], $day['cleanings'], 'Basic-mode IČO must not leak per-cleaning details');

        $serialised = json_encode($result);
        $this->assertStringNotContainsString('EMP001', $serialised, 'personal_number must not leak');
        $this->assertStringNotContainsString('18:00', $serialised, 'scan time must not leak');
    }

    public function testGetCleaningDaysForCompaniesToleratesOngoingFetchFailure(): void
    {
        // attendance-raw / employees can fail transiently — the calendar must
        // still render the cached historical data. getOngoingProjectReports()
        // signals failure with null; the service treats it as "no ongoing
        // records this turn" rather than blanking out the calendar.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        [$y, $m] = array_map('intval', explode('-', $today));

        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([
            [
                'date' => $today,
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:00:00',
            ],
        ]);
        $this->clientMock->method('getOngoingProjectReports')->willReturn(null);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompanies(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            $y,
            $m
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertFalse($result['cleaningDays'][0]['ongoing'], 'Scanned-out historical record stays finished');
    }

    // getCleaningDaysForCompaniesRange — Docházka overview fetch

    private static function basicRecord(string $date): array
    {
        return [
            'date' => $date,
            'project' => ['name' => '12345678 Office'],
            'employee' => ['personal_number' => 'EMP001'],
            'first_scan_time' => '08:00:00',
            'last_scan_time' => '10:00:00',
        ];
    }

    public function testRangeInactiveWhenNoNonOffIco(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->expects($this->never())->method('getProjectReports');

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'off']],
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-12-31')
        );

        $this->assertFalse($result['active']);
        $this->assertSame([], $result['cleaningDays']);
    }

    public function testRangeFetchesEachSpannedYearOnceWithNullMonth(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $seenYears = [];
        $this->clientMock->expects($this->exactly(2))
            ->method('getProjectReports')
            ->willReturnCallback(function (int $year, ?int $month) use (&$seenYears) {
                $this->assertNull($month, 'Range fetch must omit month to pull the whole year');
                $seenYears[] = $year;
                return [self::basicRecord($year . '-06-15')];
            });
        // A past window → no ongoing merge.
        $this->clientMock->expects($this->never())->method('getOngoingProjectReports');
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2025-08-31')
        );

        $this->assertSame([2024, 2025], $seenYears);
        $this->assertTrue($result['active']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['cleaningDays']);
    }

    public function testRangeFiltersRecordsOutsideTheWindow(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([
            self::basicRecord('2024-01-10'),  // before window
            self::basicRecord('2024-06-15'),  // inside
            self::basicRecord('2024-11-20'),  // after window
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-07-31')
        );

        $this->assertCount(1, $result['cleaningDays']);
        $this->assertSame('2024-06-15', $result['cleaningDays'][0]['date']);
    }

    public function testRangePartialYearFailureFlagsErrorButReturnsData(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturnCallback(
            fn (int $year, ?int $month) => $year === 2024 ? [self::basicRecord('2024-06-15')] : null
        );
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2025-08-31')
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertNotNull($result['error'], 'Partial failure must flag incomplete data');
    }

    public function testRangeTotalFailureStaysActiveWithError(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn(null);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-08-31')
        );

        $this->assertTrue($result['active']);
        $this->assertSame([], $result['cleaningDays']);
        $this->assertNotNull($result['error']);
    }

    public function testRangeSwapsReversedBounds(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReports')->willReturn([self::basicRecord('2024-06-15')]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-07-31'),
            new \DateTimeImmutable('2024-05-01')
        );

        $this->assertCount(1, $result['cleaningDays']);
        $this->assertSame('2024-06-15', $result['cleaningDays'][0]['date']);
    }
}
