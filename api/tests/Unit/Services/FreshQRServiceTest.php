<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Helpers\FreshQRClient;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyRoundingRuleRepository;
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
    private MockObject&CompanyRoundingRuleRepository $roundingRuleRepoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(FreshQRClient::class);
        $this->companyRepoMock = $this->createMock(CompanyRepository::class);
        $this->employeeRepoMock = $this->createMock(EmployeeRepository::class);
        $this->roundingRuleRepoMock = $this->createMock(CompanyRoundingRuleRepository::class);

        $reflection = new ReflectionClass(FreshQRService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'client' => $this->clientMock,
            'companyRepo' => $this->companyRepoMock,
            'employeeRepo' => $this->employeeRepoMock,
            'roundingRuleRepo' => $this->roundingRuleRepoMock,
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
        array $displayNames = [],
        ?\DateTimeImmutable $now = null
    ): array {
        $modeMap = [];
        foreach ($icos as $ico) {
            $modeMap[$ico] = $mode;
        }
        return FreshQRService::buildCleaningDays($records, $modeMap, $allowed, $displayNames, $today, [], $now);
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

    public function testBuildCleaningDaysTreatsEqualScanTimesAsClosedNotOngoing(): void
    {
        // last_scan_time equal to first_scan_time is a recorded scan-out, not an
        // "on-site" placeholder: neither source echoes the scan-in into
        // last_scan_time (open records carry null), so equality means a real
        // zero-length pair from an accidental double-scan. It must read as
        // closed even on today's date — treating it as open once kept a whole
        // day flagged "Probíhá" after an overnight cleaning's post-midnight
        // double-scan.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '08:00:00',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
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

    public function testBuildCleaningDaysClosedRecordWithUnparseableLastScanIsNotOngoing(): void
    {
        // A present-but-unparseable last_scan_time still counts as a recorded
        // scan-out: the entry is closed (never "Probíhá"), only its endTime is
        // omitted instead of rendering garbage.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => 'not-a-time',
            ],
        ];

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678' => 'detailed'],
            ['EMP001' => 0],
            ['EMP001' => 'Anna N.'],
            '2026-04-21'
        );

        $this->assertFalse($result[0]['ongoing']);
        $cleaning = $result[0]['cleanings'][0];
        $this->assertFalse($cleaning['ongoing']);
        $this->assertNull($cleaning['endTime']);
        $this->assertNull($cleaning['rawMinutes']);
    }

    public function testBuildCleaningDaysDoesNotFlagDoubleScanGhostFromReportCacheAsOngoing(): void
    {
        // Regression (real data, night of 2026-07-11→12): the cleaner scanned
        // out at 02:08 and tapped again, leaving a zero-length pair. The raw
        // path drops it at reshape, but the materialized report cache still
        // collapses it into a row whose TIME-formatted first and last scans
        // are identical — that row must not flag the day as ongoing.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => 'Customer 12345678 HQ'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '02:08',
                'last_scan_time' => '02:08',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysFlagsOngoingWhenAtLeastOneEmployeeIsStillOnSite(): void
    {
        // Two employees today at the matching project. EMP001 scanned out,
        // EMP002 is still on-site (arrival recorded, no scan-out yet). The
        // day's aggregate `ongoing` flag is the OR across cleanings, so EMP002
        // keeps the day open even though EMP001 is finished.
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
        // HH:MM:SS, an ISO-format last_scan_time still counts as a recorded
        // scan-out and closes the cleaning.
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

    public function testBuildCleaningDaysKeepsReportRowWhereFirstScanIsPreviousDay(): void
    {
        // Overnight cleanings are legitimate: a report row whose ISO first_scan_time
        // is the night before is kept on its reported `date` (the day FreshQR's
        // cache grouped it under), not dropped. Basic mode exposes no times, only
        // that a cleaning happened that day.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-20T23:50:00Z',
                'last_scan_time' => '2026-04-21T00:10:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertSame('2026-04-21', $result[0]['date']);
    }

    public function testBuildCleaningDaysKeepsReportRowWhereLastScanIsNextDay(): void
    {
        // Mirror of the above — a scan-out spilling into the next day is a genuine
        // overnight visit, kept on its reported start-day `date`.
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '2026-04-21T23:50:00Z',
                'last_scan_time' => '2026-04-22T00:10:00Z',
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertSame('2026-04-21', $result[0]['date']);
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

    public function testBuildCleaningDaysDetailedOvernightVisitAnchoredToStartDayWithDuration(): void
    {
        // Detailed-mode overnight cleaning (23:30 → 01:00, reshaped upstream with an
        // explicit duration_minutes) lands on its START day and carries the
        // gap-aware duration; the reversed start/end is what the UI marks as "+1".
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '23:30:00',
                'last_scan_time' => '01:00:00',
                'duration_minutes' => 90,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21', 'detailed');

        $this->assertCount(1, $result);
        $this->assertSame('2026-04-20', $result[0]['date']);
        $this->assertFalse($result[0]['ongoing']);
        $cleaning = $result[0]['cleanings'][0];
        $this->assertSame('23:30', $cleaning['startTime']);
        $this->assertSame('01:00', $cleaning['endTime']);
        $this->assertSame(90, $cleaning['rawMinutes']);
        // The end (01:00) reads earlier than the start (23:30): that lexical
        // reversal is the signal the FE turns into a "+1 day" marker.
        $this->assertLessThan($cleaning['startTime'], $cleaning['endTime']);
    }

    public function testBuildCleaningDaysKeepsMorningAndEveningEntriesExceedingTwelveHoursTotal(): void
    {
        // The per-entry cap is not a per-day cap: a large site with a separate
        // morning and evening shift produces two entries whose combined span tops
        // 12 hours. Each is well under the cap, so both survive and both durations
        // count — nothing is collapsed or dropped.
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '06:00:00',
                'last_scan_time' => '11:00:00',
                'duration_minutes' => 300,
            ],
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '18:00:00',
                'last_scan_time' => '00:30:00',
                'duration_minutes' => 390,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21', 'detailed');

        $this->assertCount(1, $result);
        $cleanings = $result[0]['cleanings'];
        $this->assertCount(2, $cleanings);
        $this->assertSame(300, $cleanings[0]['rawMinutes']);
        $this->assertSame(390, $cleanings[1]['rawMinutes']);
        // Evening entry crossed midnight — its end (00:30) reads before its start.
        $this->assertLessThan($cleanings[1]['startTime'], $cleanings[1]['endTime']);
    }

    public function testBuildCleaningDaysFlagsOvernightInProgressAsOngoing(): void
    {
        // A cleaning that started 23:00 yesterday and is still open at 00:45 now
        // (well within one entry's max length) is live — flagged ongoing even
        // though its start day is yesterday, not today.
        $now = new \DateTimeImmutable('2026-04-21 00:45:00', new \DateTimeZone('Europe/Prague'));
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '23:00:00',
                'last_scan_time' => null,
                'duration_minutes' => null,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21', 'detailed', [], $now);

        $this->assertCount(1, $result);
        $this->assertSame('2026-04-20', $result[0]['date']);
        $this->assertTrue($result[0]['ongoing']);
        $this->assertTrue($result[0]['cleanings'][0]['ongoing']);
        // A still-running cleaning has no final duration and must never bill.
        $this->assertNull($result[0]['cleanings'][0]['rawMinutes']);
    }

    public function testBuildCleaningDaysOngoingEntryNeverBillsEvenWithReportedDuration(): void
    {
        // Defensive: if FreshQR ever attaches a duration to a record with no
        // scan-out (open pair), the entry is still ongoing and must contribute
        // zero — rawMinutes stays null so it can't leak into billed totals while
        // the FE shows "Probíhá".
        $now = new \DateTimeImmutable('2026-04-21 08:15:00', new \DateTimeZone('Europe/Prague'));
        $records = [
            [
                'date' => '2026-04-21',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => null,
                'duration_minutes' => 240,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21', 'detailed', [], $now);

        $this->assertTrue($result[0]['cleanings'][0]['ongoing']);
        $this->assertNull($result[0]['cleanings'][0]['rawMinutes']);
    }

    public function testBuildCleaningDaysDoesNotFlagStaleOpenScanAsOngoing(): void
    {
        // Same open record, but now it's the following afternoon — 15+ hours after
        // scan-in, past the per-entry window. That's a forgotten scan-out, not live
        // work, so it must NOT be ongoing.
        $now = new \DateTimeImmutable('2026-04-21 14:00:00', new \DateTimeZone('Europe/Prague'));
        $records = [
            [
                'date' => '2026-04-20',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '23:00:00',
                'last_scan_time' => null,
                'duration_minutes' => null,
            ],
        ];

        $result = self::callBuild($records, ['12345678'], ['EMP001' => 0], '2026-04-21', 'detailed', [], $now);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['ongoing']);
    }

    public function testBuildCleaningDaysSubMinuteClosedPairIsFinishedNotOngoing(): void
    {
        // A recorded scan-out seconds after the scan-in (first='08:00:30',
        // last='08:00:55') is a CLOSED zero-length pair — double-scan noise the
        // raw path normally drops at reshape. If one still reaches the builder
        // (e.g. via the report cache), it must read as finished: ongoing=false
        // with the endTime present, never the contradictory ongoing+endTime mix.
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
        $this->assertFalse($cleaning['ongoing'], 'A recorded scan-out closes the cleaning even seconds after scan-in');
        $this->assertSame('08:00', $cleaning['endTime'], 'endTime must agree with ongoing — no contradictory state');
        $this->assertFalse($result[0]['ongoing']);
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
        // Only a missing last_scan_time means "still on-site" (null endTime).
        // An equal last_scan_time is a recorded scan-out — zero-length pair —
        // so its endTime is populated like any other closed cleaning.
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '09:00:00',
                'last_scan_time' => '09:00:00', // recorded scan-out, zero-length pair
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP002'],
                'first_scan_time' => '10:00:00',
                // last_scan_time absent → still on-site
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
        $this->assertEquals('09:00', $result[0]['cleanings'][0]['endTime']);
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

    /**
     * The reported bug: one worker visiting one object twice in a day must show
     * two separate cleaning records, each timed from its own scan-in/out — not a
     * single 08:00–16:00 span that swallows the midday gap. With attendance-raw
     * feeding one record per scan pair, buildCleaningDays emits one cleaning per
     * pair, so the split and the gap-free durations fall out naturally.
     */
    public function testBuildCleaningDaysSplitsRepeatVisitsToTheSameObject(): void
    {
        $records = [
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => '10:00:00',
            ],
            [
                'date' => '2026-04-10',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '14:00:00',
                'last_scan_time' => '16:00:00',
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

        $cleanings = $result[0]['cleanings'];
        $this->assertCount(2, $cleanings);

        $this->assertEquals(['08:00', '14:00'], array_column($cleanings, 'startTime'));
        $this->assertEquals(['10:00', '16:00'], array_column($cleanings, 'endTime'));

        // Each visit is billed on its own two hours — never the 8h wall-clock
        // span between first arrival and last departure.
        $this->assertSame([120, 120], array_column($cleanings, 'rawMinutes'));
        $this->assertSame(240, array_sum(array_column($cleanings, 'rawMinutes')));
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
                // no last_scan_time → still on-site
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

    public function testGetCleaningDaysForCompaniesDetailedModeSplitsVisitsFromAttendanceRaw(): void
    {
        // Detailed mode must source per-visit scan pairs from attendance-raw and
        // never touch the collapsing materialized report — otherwise a same-object
        // repeat visit would come back as one gap-spanning record again.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->expects($this->never())->method('getProjectReports');
        $this->clientMock->expects($this->once())
            ->method('getAttendanceRawForRange')
            ->willReturn([
                [
                    'date' => '2026-04-10',
                    'project' => ['name' => '12345678 Office'],
                    'employee' => ['personal_number' => 'EMP001'],
                    'first_scan_time' => '08:00:00',
                    'last_scan_time' => '10:00:00',
                ],
                [
                    'date' => '2026-04-10',
                    'project' => ['name' => '12345678 Office'],
                    'employee' => ['personal_number' => 'EMP001'],
                    'first_scan_time' => '14:00:00',
                    'last_scan_time' => '16:00:00',
                ],
            ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        $this->employeeRepoMock->method('findDisplayNamesByPersonalIds')->willReturn(['EMP001' => 'Anna N.']);
        $this->roundingRuleRepoMock->method('findByCompanyIds')->willReturn([]);

        $result = $this->service->getCleaningDaysForCompanies(
            [['id' => 1, 'registration_number' => '12345678', 'freshqr_mode' => 'detailed']],
            2026,
            4
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $cleanings = $result['cleaningDays'][0]['cleanings'];
        $this->assertCount(2, $cleanings);
        $this->assertSame(['08:00', '14:00'], array_column($cleanings, 'startTime'));
        $this->assertSame([120, 120], array_column($cleanings, 'rawMinutes'));
    }

    public function testGetCleaningDaysForCompaniesDetailedModeHidesPastForgottenScanOuts(): void
    {
        // attendance-raw carries unfinished pairs; on a past day that's a forgotten
        // scan-out the old cached path never showed. It must be dropped, while
        // today's still-open scan is kept as the ongoing indicator.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
        [$y, $m] = array_map('intval', explode('-', $today));

        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getAttendanceRawForRange')->willReturn([
            [
                'date' => '2020-01-15',
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '08:00:00',
                'last_scan_time' => null,
            ],
            [
                'date' => $today,
                'project' => ['name' => '12345678 Office'],
                'employee' => ['personal_number' => 'EMP001'],
                'first_scan_time' => '09:00:00',
                'last_scan_time' => null,
            ],
        ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        $this->employeeRepoMock->method('findDisplayNamesByPersonalIds')->willReturn(['EMP001' => 'Anna N.']);
        $this->roundingRuleRepoMock->method('findByCompanyIds')->willReturn([]);

        $result = $this->service->getCleaningDaysForCompanies(
            [['id' => 1, 'registration_number' => '12345678', 'freshqr_mode' => 'detailed']],
            $y,
            $m
        );

        $dates = array_column($result['cleaningDays'], 'date');
        $this->assertNotContains('2020-01-15', $dates, 'past forgotten scan-out must be hidden');
        $this->assertContains($today, $dates, "today's open scan must survive");

        $todayDay = array_values(array_filter(
            $result['cleaningDays'],
            static fn ($d) => $d['date'] === $today
        ))[0];
        $this->assertTrue($todayDay['ongoing']);
    }

    public function testGetCleaningDaysForCompaniesDetailedModeSurfacesErrorWhenAttendanceRawFails(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getAttendanceRawForRange')->willReturn(null);

        $result = $this->service->getCleaningDaysForCompanies(
            [['id' => 1, 'registration_number' => '12345678', 'freshqr_mode' => 'detailed']],
            2026,
            4
        );

        $this->assertTrue($result['active']);
        $this->assertSame([], $result['cleaningDays']);
        $this->assertNotNull($result['error']);
    }

    public function testRangeDetailedModeSourcesFromAttendanceRawNotMaterializedReport(): void
    {
        // The overview's detailed path fetches one ranged attendance-raw window
        // (chunked inside the client) rather than the per-month materialized report.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->expects($this->never())->method('getProjectReportsForMonths');
        $this->clientMock->expects($this->never())->method('getOngoingProjectReports');
        $this->clientMock->expects($this->once())
            ->method('getAttendanceRawForRange')
            ->willReturn([
                [
                    'date' => '2024-06-15',
                    'project' => ['name' => '12345678 Office'],
                    'employee' => ['personal_number' => 'EMP001'],
                    'first_scan_time' => '08:00:00',
                    'last_scan_time' => '10:00:00',
                ],
                [
                    'date' => '2024-06-15',
                    'project' => ['name' => '12345678 Office'],
                    'employee' => ['personal_number' => 'EMP001'],
                    'first_scan_time' => '13:00:00',
                    'last_scan_time' => '15:00:00',
                ],
            ]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);
        $this->employeeRepoMock->method('findDisplayNamesByPersonalIds')->willReturn(['EMP001' => 'Anna N.']);
        $this->roundingRuleRepoMock->method('findByCompanyIds')->willReturn([]);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['id' => 1, 'registration_number' => '12345678', 'freshqr_mode' => 'detailed']],
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-06-30')
        );

        $this->assertTrue($result['active']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertCount(2, $result['cleaningDays'][0]['cleanings']);
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

    /**
     * All months succeeded, no records — 'YYYY-MM' => [] for every pair.
     *
     * @param list<array{0:int,1:int}> $months
     * @return array<string,array>
     */
    private static function emptyMonthResults(array $months): array
    {
        $out = [];
        foreach ($months as [$y, $m]) {
            $out[sprintf('%04d-%02d', $y, $m)] = [];
        }
        return $out;
    }

    public function testRangeInactiveWhenNoNonOffIco(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->expects($this->never())->method('getProjectReportsForMonths');

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'off']],
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-12-31')
        );

        $this->assertFalse($result['active']);
        $this->assertSame([], $result['cleaningDays']);
    }

    public function testRangeFetchesEverySpannedMonthInOneBatch(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $seenMonths = [];
        $this->clientMock->expects($this->once())
            ->method('getProjectReportsForMonths')
            ->willReturnCallback(function (array $months) use (&$seenMonths) {
                $seenMonths = $months;
                $out = self::emptyMonthResults($months);
                $out['2024-06'] = [self::basicRecord('2024-06-15')];
                $out['2025-06'] = [self::basicRecord('2025-06-15')];
                return $out;
            });
        // A past window → no ongoing merge.
        $this->clientMock->expects($this->never())->method('getOngoingProjectReports');
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2025-08-31')
        );

        $this->assertCount(16, $seenMonths, '2024-05 through 2025-08 inclusive');
        $this->assertSame([2024, 5], $seenMonths[0]);
        $this->assertSame([2025, 8], $seenMonths[15]);
        $this->assertTrue($result['active']);
        $this->assertNull($result['error']);
        $this->assertCount(2, $result['cleaningDays']);
    }

    public function testRangeSkipsFutureMonths(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $seenMonths = [];
        $this->clientMock->expects($this->once())
            ->method('getProjectReportsForMonths')
            ->willReturnCallback(function (array $months) use (&$seenMonths) {
                $seenMonths = $months;
                return self::emptyMonthResults($months);
            });
        $this->clientMock->method('getOngoingProjectReports')->willReturn([]);
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        $from = $today->modify('first day of this month');

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            $from,
            $from->modify('+6 months')->modify('last day of this month')
        );

        $this->assertSame(
            [[(int) $today->format('Y'), (int) $today->format('n')]],
            $seenMonths,
            'Only the current month may be fetched; future months carry no data'
        );
        $this->assertTrue($result['active']);
        $this->assertNull($result['error']);
    }

    public function testRangeFiltersRecordsOutsideTheWindow(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReportsForMonths')->willReturnCallback(
            function (array $months) {
                $out = self::emptyMonthResults($months);
                $out[array_key_first($out)] = [
                    self::basicRecord('2024-01-10'),  // before window
                    self::basicRecord('2024-06-15'),  // inside
                    self::basicRecord('2024-11-20'),  // after window
                ];
                return $out;
            }
        );
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-07-31')
        );

        $this->assertCount(1, $result['cleaningDays']);
        $this->assertSame('2024-06-15', $result['cleaningDays'][0]['date']);
    }

    public function testRangePartialMonthFailureFlagsErrorButReturnsData(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReportsForMonths')->willReturnCallback(
            function (array $months) {
                $out = self::emptyMonthResults($months);
                $out['2024-06'] = [self::basicRecord('2024-06-15')];
                $out['2024-07'] = null;
                return $out;
            }
        );
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-08-31')
        );

        $this->assertTrue($result['active']);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertNotNull($result['error'], 'Partial failure must flag incomplete data');
    }

    public function testRangeTotalFailureStaysActiveWithError(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getProjectReportsForMonths')->willReturnCallback(
            fn (array $months) => array_fill_keys(
                array_map(static fn ($ym) => sprintf('%04d-%02d', $ym[0], $ym[1]), $months),
                null
            )
        );
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
        $seenMonths = [];
        $this->clientMock->method('getProjectReportsForMonths')->willReturnCallback(
            function (array $months) use (&$seenMonths) {
                $seenMonths = $months;
                $out = self::emptyMonthResults($months);
                $out['2024-06'] = [self::basicRecord('2024-06-15')];
                return $out;
            }
        );
        $this->employeeRepoMock->method('getAllPersonalIds')->willReturn(['EMP001']);

        $result = $this->service->getCleaningDaysForCompaniesRange(
            [['registration_number' => '12345678', 'freshqr_mode' => 'basic']],
            new \DateTimeImmutable('2024-07-31'),
            new \DateTimeImmutable('2024-05-01')
        );

        $this->assertSame([[2024, 5], [2024, 6], [2024, 7]], $seenMonths);
        $this->assertCount(1, $result['cleaningDays']);
        $this->assertSame('2024-06-15', $result['cleaningDays'][0]['date']);
    }
}
