<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceSummaryService;
use Tests\TestCase;

class AttendanceSummaryServiceTest extends TestCase
{
    public function testReturnsEmptyForEmptyInputs(): void
    {
        $this->assertSame([], AttendanceSummaryService::buildHourlySummary([], []));
    }

    public function testReturnsEmptyWhenNoCompanyIsHourly(): void
    {
        $companies = [
            self::company('12345678', 'Firma A', 'fixed', null),
            self::company('87654321', 'Firma B', null, null),
        ];
        $cleaningDays = [self::day('2026-05-10', [self::cleaning('12345678', 60, null)])];

        $this->assertSame([], AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays));
    }

    public function testRowEmittedEvenWhenIcoHasNoCleanings(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', '250.00')];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertCount(1, $result);
        $this->assertSame('12345678', $result[0]['ico']);
        $this->assertSame('Firma A', $result[0]['companyName']);
        $this->assertSame(250.0, $result[0]['hourlyRate']);
        $this->assertSame(0, $result[0]['totalMinutes']);
    }

    public function testSumsRoundedMinutesWhenAvailable(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', '300.00')];
        $cleaningDays = [
            self::day('2026-05-10', [
                self::cleaning('12345678', 210, 240), // 4h rounded (not 3.5h raw)
            ]),
            self::day('2026-05-12', [
                self::cleaning('12345678', 90, 120), // 2h rounded (not 1.5h raw)
            ]),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(360, $result[0]['totalMinutes']);
    }

    public function testFallsBackToRawMinutesWhenRoundedAbsent(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', '250.00')];
        $cleaningDays = [
            self::day('2026-05-10', [
                self::cleaning('12345678', 90, null), // no rounding rules → 90 raw
                self::cleaning('12345678', 30, null),
            ]),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(120, $result[0]['totalMinutes']);
    }

    public function testSkipsCleaningsWithBothMinuteFieldsNullOrZero(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', null)];
        $cleaningDays = [
            self::day('2026-05-10', [
                self::cleaning('12345678', null, null),
                self::cleaning('12345678', 0, 0),
                self::cleaning('12345678', 60, null),
            ]),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(60, $result[0]['totalMinutes']);
    }

    public function testOnlyHourlyCompaniesAppearInOutput(): void
    {
        $companies = [
            self::company('12345678', 'Firma A', 'hourly', '250.00'),
            self::company('87654321', 'Firma B', 'fixed', null),
            self::company('11223344', 'Firma C', null, null),
            self::company('99887766', 'Firma D', 'hourly', '180.00'),
        ];
        $cleaningDays = [self::day('2026-05-10', [
            self::cleaning('12345678', 60, null),
            self::cleaning('87654321', 60, null), // fixed → ignored
            self::cleaning('11223344', 60, null), // null billing → ignored
            self::cleaning('99887766', 90, null),
        ])];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertCount(2, $result);
        $this->assertSame(['12345678', '99887766'], array_column($result, 'ico'));
        $this->assertSame(60, $result[0]['totalMinutes']);
        $this->assertSame(90, $result[1]['totalMinutes']);
    }

    public function testHourlyRateZeroOrNegativeNormalisesToNull(): void
    {
        $companies = [
            self::company('12345678', 'Firma A', 'hourly', '0.00'),
            self::company('87654321', 'Firma B', 'hourly', '-10.00'),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertNull($result[0]['hourlyRate']);
        $this->assertNull($result[1]['hourlyRate']);
    }

    public function testHourlyRatePassesThroughAsFloat(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', '250.50')];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertSame(250.5, $result[0]['hourlyRate']);
    }

    public function testFallsBackToIcoWhenNameMissing(): void
    {
        $companies = [self::company('12345678', '', 'hourly', null)];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertSame('12345678', $result[0]['companyName']);
    }

    public function testTrimsCompanyName(): void
    {
        $companies = [self::company('12345678', '  Firma A  ', 'hourly', null)];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertSame('Firma A', $result[0]['companyName']);
    }

    public function testIgnoresCleaningsWithUnknownOrMalformedIco(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', null)];
        $cleaningDays = [self::day('2026-05-10', [
            self::cleaning('12345678', 60, null),
            self::cleaning('99999999', 60, null), // unknown — irrelevant to total
            self::cleaning('abc', 60, null),       // malformed
            self::cleaning('', 60, null),          // empty
        ])];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(60, $result[0]['totalMinutes']);
    }

    public function testHourlyCompanyWithMalformedRegistrationNumberIsDropped(): void
    {
        $companies = [
            self::company('abc', 'Firma A', 'hourly', null),
            self::company('12345678', 'Firma B', 'hourly', null),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertCount(1, $result);
        $this->assertSame('12345678', $result[0]['ico']);
    }

    public function testBillingModelMatchIsCaseInsensitive(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'HOURLY', '250.00')];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertCount(1, $result);
    }

    public function testRoundedMinutesZeroCountsAsBillableZeroAndDoesNotFallBackToRaw(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', '250.00')];
        // Sub-threshold visit (10 minutes) rounded down to 0 — must NOT count
        // the raw 10 minutes; the rounded value is the billable truth.
        $cleaningDays = [self::day('2026-05-10', [
            self::cleaning('12345678', 10, 0),
            self::cleaning('12345678', 90, 90),
        ])];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(90, $result[0]['totalMinutes']);
    }

    public function testHourlyRateAcceptsNativeFloat(): void
    {
        // PDO normally returns DECIMAL as string, but other code paths may pass
        // a native float — the normaliser must accept both.
        $companies = [[
            'registration_number' => '12345678',
            'name' => 'Firma A',
            'billing_model' => 'hourly',
            'hourly_rate' => 199.5,
        ]];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertSame(199.5, $result[0]['hourlyRate']);
    }

    public function testAbsentHourlyRateFieldNormalisesToNull(): void
    {
        $companies = [[
            'registration_number' => '12345678',
            'name' => 'Firma A',
            'billing_model' => 'hourly',
            // hourly_rate key intentionally absent
        ]];

        $result = AttendanceSummaryService::buildHourlySummary($companies, []);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['hourlyRate']);
    }

    public function testCleaningDaysWithMissingCleaningsArrayAreSkipped(): void
    {
        $companies = [self::company('12345678', 'Firma A', 'hourly', null)];
        $cleaningDays = [
            ['date' => '2026-05-10', 'ongoing' => false], // no cleanings key
            ['date' => '2026-05-11', 'ongoing' => false, 'cleanings' => null],
            self::day('2026-05-12', [self::cleaning('12345678', 45, null)]),
        ];

        $result = AttendanceSummaryService::buildHourlySummary($companies, $cleaningDays);

        $this->assertSame(45, $result[0]['totalMinutes']);
    }

    /**
     * @return array<string,mixed>
     */
    private static function company(string $ico, string $name, ?string $billingModel, ?string $hourlyRate): array
    {
        return [
            'registration_number' => $ico,
            'name' => $name,
            'billing_model' => $billingModel,
            'hourly_rate' => $hourlyRate,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function cleaning(string $ico, ?int $rawMinutes, ?int $roundedMinutes): array
    {
        return [
            'employee' => 'Anna N.',
            'startTime' => '08:00',
            'endTime' => '11:30',
            'note' => null,
            'ico' => $ico,
            'rawMinutes' => $rawMinutes,
            'roundedMinutes' => $roundedMinutes,
        ];
    }

    /**
     * @param list<array<string,mixed>> $cleanings
     * @return array<string,mixed>
     */
    private static function day(string $date, array $cleanings): array
    {
        return [
            'date' => $date,
            'ongoing' => false,
            'cleanings' => $cleanings,
        ];
    }
}
