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

    // buildCleaningDays — mapping rules

    public function testBuildCleaningDaysReturnsEmptyWhenNoRecords(): void
    {
        $result = FreshQRService::buildCleaningDays([], ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertEquals([], $result);
    }

    public function testBuildCleaningDaysMatchesIcoAtStartMiddleAndEndOfName(): void
    {
        $records = [
            ['date' => '2026-04-10', 'project' => ['name' => '12345678 Main'],       'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-11', 'project' => ['name' => 'Office 12345678 HQ'],  'employee' => ['personal_number' => 'EMP001']],
            ['date' => '2026-04-12', 'project' => ['name' => 'Office 12345678'],     'employee' => ['personal_number' => 'EMP001']],
        ];

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays(
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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays(
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

        $result = FreshQRService::buildCleaningDays(
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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

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

        $result = FreshQRService::buildCleaningDays(
            $records,
            ['12345678', '87654321'],
            ['EMP001' => 0],
            '2026-04-21'
        );

        $this->assertCount(1, $result);
        $this->assertEquals('2026-04-10', $result[0]['date']);
    }

    public function testBuildCleaningDaysNeverLeaksInternalFields(): void
    {
        // The API promises the client only sees date + ongoing. Make sure no
        // scan times, employee info or duration sneak through.
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

        $result = FreshQRService::buildCleaningDays($records, ['12345678'], ['EMP001' => 0], '2026-04-21');

        $this->assertCount(1, $result);
        $this->assertEquals(['date', 'ongoing'], array_keys($result[0]));
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

    public function testGetCleaningDaysForUserReturnsActiveWithErrorWhenApiCallFails(): void
    {
        // Configured + has IČOs + API failed → keep calendar visible but surface
        // an error string, so the FE can show a banner rather than onboarding.
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678'],
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
            ['registration_number' => '12345678'],
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

    public function testGetCleaningDaysForUserReturnsActiveEmptyWhenNoMatches(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['registration_number' => '12345678'],
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
            ['registration_number' => '12345678'],
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
