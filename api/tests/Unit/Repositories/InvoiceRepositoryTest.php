<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\InvoiceRepository;
use PDO;
use Tests\DatabaseTestCase;

class InvoiceRepositoryTest extends DatabaseTestCase
{
    private InvoiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(InvoiceRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsInvoiceWithCompanyData(): void
    {
        $expectedInvoice = [
            'id' => 1,
            'idoklad_id' => 12345,
            'company_id' => 1,
            'document_number' => 'INV-2024-001',
            'variable_symbol' => '2024001',
            'date_issued' => '2024-01-01',
            'date_due' => '2024-01-15',
            'date_paid' => null,
            'total_amount' => 1000.00,
            'currency_code' => 'CZK',
            'is_paid' => 0,
            'payment_status' => 'unpaid',
            'description' => 'Cleaning services',
            'synced_at' => '2024-01-01 12:00:00',
            'company_name' => 'Test Company',
            'registration_number' => '12345678',
        ];

        $this->setupFetchMock($expectedInvoice);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedInvoice, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findByIdokladId tests

    public function testFindByIdokladIdReturnsInvoiceWhenFound(): void
    {
        $expectedInvoice = [
            'id' => 1,
            'idoklad_id' => 12345,
            'document_number' => 'INV-2024-001',
        ];

        $this->setupFetchMock($expectedInvoice);

        $result = $this->repository->findByIdokladId(12345);

        $this->assertEquals($expectedInvoice, $result);
    }

    public function testFindByIdokladIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByIdokladId(99999);

        $this->assertNull($result);
    }

    // findByCompanyId tests

    public function testFindByCompanyIdReturnsInvoices(): void
    {
        $expectedInvoices = [
            ['id' => 1, 'document_number' => 'INV-001', 'company_id' => 1],
            ['id' => 2, 'document_number' => 'INV-002', 'company_id' => 1],
        ];

        $this->setupFetchAllMock($expectedInvoices);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals($expectedInvoices, $result);
    }

    public function testFindByCompanyIdWithStatusFilter(): void
    {
        $expectedInvoices = [
            ['id' => 1, 'payment_status' => 'paid'],
        ];

        $this->setupFetchAllMock($expectedInvoices);

        $result = $this->repository->findByCompanyId(1, 'paid');

        $this->assertEquals($expectedInvoices, $result);
    }

    public function testFindByCompanyIdOrdersByDateDesc(): void
    {
        $expectedInvoices = [
            ['id' => 2, 'date_issued' => '2024-02-01'],
            ['id' => 1, 'date_issued' => '2024-01-01'],
        ];

        $this->setupFetchAllMock($expectedInvoices);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals('2024-02-01', $result[0]['date_issued']);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsInvoices(): void
    {
        $expectedInvoices = [
            ['id' => 1, 'company_name' => 'Company A'],
            ['id' => 2, 'company_name' => 'Company B'],
        ];

        $this->setupFetchAllMock($expectedInvoices);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expectedInvoices, $result);
    }

    public function testFindByUserIdWithIcoFilter(): void
    {
        $expectedInvoices = [
            ['id' => 1, 'registration_number' => '12345678'],
        ];

        $this->setupFetchAllMock($expectedInvoices);

        $result = $this->repository->findByUserId(1, '12345678');

        $this->assertEquals($expectedInvoices, $result);
    }

    // getTotalsForUser tests

    public function testGetTotalsForUserReturnsAggregations(): void
    {
        $this->stmtMock->method('fetch')->willReturn([
            'total_count' => 10,
            'paid_count' => 5,
            'unpaid_count' => 3,
            'overdue_count' => 2,
            'debt_amount' => 5000.00,
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getTotalsForUser(1);

        $this->assertEquals(10, $result['all']);
        $this->assertEquals(5, $result['paid']);
        $this->assertEquals(3, $result['unpaid']);
        $this->assertEquals(2, $result['overdue']);
        $this->assertEquals(5000.00, $result['debt']);
    }

    public function testGetTotalsForUserWithIcoFilter(): void
    {
        $this->stmtMock->method('fetch')->willReturn([
            'total_count' => 5,
            'paid_count' => 3,
            'unpaid_count' => 1,
            'overdue_count' => 1,
            'debt_amount' => 2000.00,
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getTotalsForUser(1, '12345678');

        $this->assertEquals(5, $result['all']);
    }

    public function testGetTotalsForUserReturnsZerosWhenEmpty(): void
    {
        $this->stmtMock->method('fetch')->willReturn([
            'total_count' => null,
            'paid_count' => null,
            'unpaid_count' => null,
            'overdue_count' => null,
            'debt_amount' => null,
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getTotalsForUser(1);

        $this->assertEquals(0, $result['all']);
        $this->assertEquals(0, $result['paid']);
        $this->assertEquals(0, $result['unpaid']);
        $this->assertEquals(0, $result['overdue']);
        $this->assertEquals(0.0, $result['debt']);
    }

    public function testGetTotalsForUserWithDateRangeIncludesDateBindings(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $this->pdoMock->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
        $this->stmtMock->method('fetch')->willReturn([
            'total_count' => 7,
            'paid_count' => 4,
            'unpaid_count' => 2,
            'overdue_count' => 1,
            'debt_amount' => 3000.00,
        ]);

        $result = $this->repository->getTotalsForUser(1, '12345678', '2026-01-01', '2026-04-08');

        $this->assertEquals(7, $result['all']);
        $this->assertStringContainsString('i.date_issued >= :date_from', (string) $capturedSql);
        $this->assertStringContainsString('i.date_issued <= :date_to', (string) $capturedSql);
        $this->assertSame('2026-01-01', $capturedParams['date_from'] ?? null);
        $this->assertSame('2026-04-08', $capturedParams['date_to'] ?? null);
        $this->assertSame('12345678', $capturedParams['ico'] ?? null);
    }

    // findRecentForUser tests

    public function testFindRecentForUserReturnsInvoices(): void
    {
        $expected = [
            ['id' => 2, 'document_number' => 'FV2026-002', 'date_issued' => '2026-03-01'],
            ['id' => 1, 'document_number' => 'FV2026-001', 'date_issued' => '2026-02-01'],
        ];

        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findRecentForUser(1, '12345678', '2026-01-01', '2026-04-08', 5);

        $this->assertEquals($expected, $result);
    }

    public function testFindRecentForUserUsesLimit(): void
    {
        $capturedSql = null;
        $boundValues = [];

        $this->pdoMock->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);

        $this->repository->findRecentForUser(1, null, null, null, 3);

        $this->assertStringContainsString('LIMIT :row_limit', (string) $capturedSql);
        $this->assertSame(3, $boundValues[':row_limit'] ?? null);
        $this->assertSame(1, $boundValues[':user_id'] ?? null);
    }

    public function testFindRecentForUserReturnsEmptyArrayWhenNone(): void
    {
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findRecentForUser(1);

        $this->assertSame([], $result);
    }

    // findRecentDates tests

    public function testFindRecentDatesReturnsRows(): void
    {
        $expected = [
            ['date_issued' => '2026-05-05', 'document_number' => 'FV2026-042'],
            ['date_issued' => '2026-05-04', 'document_number' => 'FV2026-041'],
        ];

        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findRecentDates(5);

        $this->assertSame($expected, $result);
    }

    public function testFindRecentDatesBindsLimit(): void
    {
        $boundValues = [];

        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);

        $this->repository->findRecentDates(7);

        $this->assertSame(7, $boundValues[':row_limit'] ?? null);
    }

    public function testFindRecentDatesOrdersByIssuedDateDesc(): void
    {
        // Order is the whole point of the freshness gauge — the secondary id DESC
        // tiebreaker keeps results deterministic when several invoices share a date.
        $capturedSql = null;

        $this->pdoMock->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);

        $this->repository->findRecentDates(5);

        $this->assertStringContainsString(
            'ORDER BY i.date_issued DESC, i.id DESC',
            (string) $capturedSql
        );
    }

    public function testFindRecentDatesClampsZeroOrNegativeLimit(): void
    {
        $boundValues = [];

        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);

        $this->repository->findRecentDates(0);

        $this->assertSame(1, $boundValues[':row_limit'] ?? null);
    }

    // findNextDueForUser tests

    public function testFindNextDueForUserReturnsInvoice(): void
    {
        $expected = [
            'id' => 42,
            'document_number' => 'FV2026-055',
            'date_due' => '2026-04-22',
            'total_amount' => 8400.00,
            'currency_code' => 'CZK',
            'payment_status' => 'unpaid',
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findNextDueForUser(1, '12345678');

        $this->assertEquals($expected, $result);
    }

    public function testFindNextDueForUserReturnsNullWhenNoUpcoming(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findNextDueForUser(1);

        $this->assertNull($result);
    }

    public function testFindNextDueForUserAppliesIcoFilter(): void
    {
        $capturedSql = null;
        $capturedParams = null;

        $this->pdoMock->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
        $this->stmtMock->method('fetch')->willReturn(false);

        $this->repository->findNextDueForUser(1, '99999999');

        $this->assertStringContainsString('c.registration_number = :ico', (string) $capturedSql);
        $this->assertStringContainsString("i.payment_status != 'paid'", (string) $capturedSql);
        $this->assertStringContainsString('i.date_due >= CURDATE()', (string) $capturedSql);
        $this->assertSame('99999999', $capturedParams['ico'] ?? null);
    }

    // upsertFromIdoklad tests

    public function testUpsertFromIdokladInsertsNewInvoice(): void
    {
        // First call returns null (not found), second call for insert
        $stmtFetch = $this->createStatementMock();
        $stmtFetch->method('fetch')->willReturn(false);
        $stmtFetch->method('execute')->willReturn(true);

        $stmtInsert = $this->createStatementMock();
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtFetch, $stmtInsert);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $result = $this->repository->upsertFromIdoklad([
            'idoklad_id' => 12345,
            'company_id' => 1,
            'document_number' => 'INV-001',
            'date_issued' => '2024-01-01',
            'date_due' => '2024-01-15',
            'total_amount' => 1000.00,
            'is_paid' => false,
            'payment_status' => 'unpaid',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testUpsertFromIdokladUpdatesExistingInvoice(): void
    {
        $existingInvoice = [
            'id' => 5,
            'idoklad_id' => 12345,
        ];

        $stmtFetch = $this->createStatementMock();
        $stmtFetch->method('fetch')->willReturn($existingInvoice);
        $stmtFetch->method('execute')->willReturn(true);

        $stmtUpdate = $this->createStatementMock();
        $stmtUpdate->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtFetch, $stmtUpdate);

        $result = $this->repository->upsertFromIdoklad([
            'idoklad_id' => 12345,
            'company_id' => 1,
            'document_number' => 'INV-001',
            'date_issued' => '2024-01-01',
            'date_due' => '2024-01-15',
            'total_amount' => 1500.00,
            'is_paid' => true,
            'payment_status' => 'paid',
        ]);

        $this->assertEquals(5, $result);
    }

    public function testUpsertFromIdokladHandlesNullDates(): void
    {
        $stmtFetch = $this->createStatementMock();
        $stmtFetch->method('fetch')->willReturn(false);
        $stmtFetch->method('execute')->willReturn(true);

        $stmtInsert = $this->createStatementMock();
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtFetch, $stmtInsert);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $result = $this->repository->upsertFromIdoklad([
            'idoklad_id' => 12345,
            'company_id' => 1,
            'document_number' => 'INV-001',
            'date_issued' => '2024-01-01',
            'date_due' => '2024-01-15',
            'date_paid' => null,
            'total_amount' => 1000.00,
            'is_paid' => false,
            'payment_status' => 'unpaid',
        ]);

        $this->assertEquals(1, $result);
    }

    // getLastSyncTime tests

    public function testGetLastSyncTimeReturnsTimestamp(): void
    {
        $this->stmtMock->method('fetch')->willReturn(['last_sync' => '2024-01-15 12:00:00']);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getLastSyncTime(1);

        $this->assertEquals('2024-01-15 12:00:00', $result);
    }

    public function testGetLastSyncTimeReturnsNullWhenNone(): void
    {
        $this->stmtMock->method('fetch')->willReturn(['last_sync' => null]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getLastSyncTime(1);

        $this->assertNull($result);
    }

    // userOwnsInvoice tests

    public function testUserOwnsInvoiceReturnsTrueWhenOwns(): void
    {
        $this->stmtMock->method('fetch')->willReturn(['cnt' => 1]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->userOwnsInvoice(1, 1);

        $this->assertTrue($result);
    }

    public function testUserOwnsInvoiceReturnsFalseWhenDoesNotOwn(): void
    {
        $this->stmtMock->method('fetch')->willReturn(['cnt' => 0]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->userOwnsInvoice(1, 999);

        $this->assertFalse($result);
    }
}
