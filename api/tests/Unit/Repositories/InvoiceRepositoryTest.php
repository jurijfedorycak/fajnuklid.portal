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
