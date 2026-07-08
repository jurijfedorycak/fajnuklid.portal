<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\FreshQRClient;
use Tests\TestCase;

class FreshQRClientTest extends TestCase
{
    /**
     * Build a FreshQRClient without invoking the real constructor so we don't
     * depend on env loading. Fills private props directly.
     */
    private function buildClient(string $apiKey = 'fqr_test_key', string $apiUrl = 'https://api.freshqr.online'): FreshQRClient
    {
        $ref = new \ReflectionClass(FreshQRClient::class);
        /** @var FreshQRClient $client */
        $client = $ref->newInstanceWithoutConstructor();

        $props = [
            'apiUrl' => $apiUrl,
            'apiKey' => $apiKey,
            'lastError' => null,
        ];
        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    public function testIsConfiguredReturnsTrueWhenKeyPresent(): void
    {
        $client = $this->buildClient('fqr_abc');

        $this->assertTrue($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenKeyEmpty(): void
    {
        $client = $this->buildClient('');

        $this->assertFalse($client->isConfigured());
    }

    public function testLastErrorStartsNull(): void
    {
        $client = $this->buildClient();

        $this->assertNull($client->getLastError());
    }

    public function testResetLastErrorClearsState(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('lastError');
        $prop->setAccessible(true);
        $prop->setValue($client, ['context' => 'test']);

        $this->assertNotNull($client->getLastError());

        $client->resetLastError();

        $this->assertNull($client->getLastError());
    }

    public function testGetProjectReportsReturnsNullAndRecordsErrorWhenNotConfigured(): void
    {
        $client = $this->buildClient('');

        $result = $client->getProjectReports(2026, 4);

        $this->assertNull($result);
        $error = $client->getLastError();
        $this->assertNotNull($error);
        $this->assertEquals('configuration', $error['context']);
    }

    public function testGetProjectReportsForMonthsFailsAllMonthsWhenNotConfigured(): void
    {
        $client = $this->buildClient('');

        $result = $client->getProjectReportsForMonths([[2026, 1], [2026, 2]]);

        $this->assertSame(['2026-01' => null, '2026-02' => null], $result);
        $error = $client->getLastError();
        $this->assertNotNull($error);
        $this->assertEquals('configuration', $error['context']);
    }

    public function testGetProjectReportsForMonthsReturnsEmptyMapForEmptyInput(): void
    {
        $client = $this->buildClient();

        $this->assertSame([], $client->getProjectReportsForMonths([]));
    }

    public function testGetAttendanceRawForRangeReturnsNullAndRecordsErrorWhenNotConfigured(): void
    {
        $client = $this->buildClient('');

        $result = $client->getAttendanceRawForRange(
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-30')
        );

        $this->assertNull($result);
        $error = $client->getLastError();
        $this->assertNotNull($error);
        $this->assertEquals('configuration', $error['context']);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string>   $idToPersonal
     * @return array<string,mixed>|null
     */
    private function reshape(array $row, array $idToPersonal): ?array
    {
        $ref = new \ReflectionMethod(FreshQRClient::class, 'reshapeRawAttendanceRecord');
        $ref->setAccessible(true);
        /** @var array<string,mixed>|null $out */
        $out = $ref->invoke(null, $row, $idToPersonal);
        return $out;
    }

    public function testReshapeClosedPairCarriesScanOutAsLastScanTime(): void
    {
        $out = $this->reshape(
            [
                'TimeFrom' => '2026-04-10 08:00:00',
                'TimeTo' => '2026-04-10 10:00:00',
                'TaskName1' => '12345678 Office',
                'CompanyEmployeeId' => 5,
            ],
            [5 => 'EMP001']
        );

        $this->assertSame([
            'date' => '2026-04-10',
            'project' => ['name' => '12345678 Office'],
            'employee' => ['personal_number' => 'EMP001'],
            'first_scan_time' => '08:00:00',
            'last_scan_time' => '10:00:00',
        ], $out);
    }

    public function testReshapeOpenPairLeavesLastScanTimeNull(): void
    {
        $out = $this->reshape(
            [
                'TimeFrom' => '2026-04-10 08:00:00',
                'TimeTo' => null,
                'TaskName1' => '12345678 Office',
                'CompanyEmployeeId' => 5,
            ],
            [5 => 'EMP001']
        );

        $this->assertNotNull($out);
        $this->assertNull($out['last_scan_time']);
        $this->assertSame('08:00:00', $out['first_scan_time']);
    }

    public function testReshapeDropsCrossMidnightPair(): void
    {
        // Overnight scan-out lands on the next day — Fajnúklid has no cross-midnight
        // cleanings, and the single-day invariant must hold, so the pair is dropped.
        $out = $this->reshape(
            [
                'TimeFrom' => '2026-04-10 23:30:00',
                'TimeTo' => '2026-04-11 01:00:00',
                'TaskName1' => '12345678 Office',
                'CompanyEmployeeId' => 5,
            ],
            [5 => 'EMP001']
        );

        $this->assertNull($out);
    }

    public function testReshapeDropsRecordForUnmappedEmployee(): void
    {
        $out = $this->reshape(
            [
                'TimeFrom' => '2026-04-10 08:00:00',
                'TimeTo' => '2026-04-10 10:00:00',
                'TaskName1' => '12345678 Office',
                'CompanyEmployeeId' => 999,
            ],
            [5 => 'EMP001']
        );

        $this->assertNull($out);
    }

    public function testReshapeDropsRecordWithMissingTaskName(): void
    {
        $out = $this->reshape(
            [
                'TimeFrom' => '2026-04-10 08:00:00',
                'TimeTo' => '2026-04-10 10:00:00',
                'TaskName1' => '',
                'CompanyEmployeeId' => 5,
            ],
            [5 => 'EMP001']
        );

        $this->assertNull($out);
    }

    public function testReadOnlyContractRejectsNonGetRequests(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $request = $ref->getMethod('request');
        $request->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $request->invoke($client, 'POST', '/v1/projects', []);
    }

    public function testReadOnlyContractRejectsPutRequests(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $request = $ref->getMethod('request');
        $request->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $request->invoke($client, 'PUT', '/v1/projects/1', []);
    }

    public function testReadOnlyContractRejectsDeleteRequests(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $request = $ref->getMethod('request');
        $request->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $request->invoke($client, 'DELETE', '/v1/projects/1', []);
    }

    public function testConstructorTrimsTrailingSlashFromApiUrl(): void
    {
        $prevUrl = $_ENV['FRESHQR_API_URL'] ?? null;
        $prevKey = $_ENV['FRESHQR_API_KEY'] ?? null;

        try {
            $_ENV['FRESHQR_API_URL'] = 'https://api.freshqr.online/';
            $_ENV['FRESHQR_API_KEY'] = 'fqr_x';

            $client = new FreshQRClient();

            $ref = new \ReflectionClass($client);
            $prop = $ref->getProperty('apiUrl');
            $prop->setAccessible(true);

            $this->assertEquals('https://api.freshqr.online', $prop->getValue($client));
        } finally {
            if ($prevUrl === null) {
                unset($_ENV['FRESHQR_API_URL']);
            } else {
                $_ENV['FRESHQR_API_URL'] = $prevUrl;
            }
            if ($prevKey === null) {
                unset($_ENV['FRESHQR_API_KEY']);
            } else {
                $_ENV['FRESHQR_API_KEY'] = $prevKey;
            }
        }
    }
}
