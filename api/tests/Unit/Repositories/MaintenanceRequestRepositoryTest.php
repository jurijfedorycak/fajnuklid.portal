<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\MaintenanceRequestRepository;
use Tests\DatabaseTestCase;

class MaintenanceRequestRepositoryTest extends DatabaseTestCase
{
    private MaintenanceRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(MaintenanceRequestRepository::class);
    }

    // findByClientId

    public function testFindByClientIdReturnsRequests(): void
    {
        $expected = [
            ['id' => 1, 'client_id' => 5, 'title' => 'Broken AC', 'status' => 'prijato'],
            ['id' => 2, 'client_id' => 5, 'title' => 'Leaking faucet', 'status' => 'resi_se'],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByClientId(5);

        $this->assertEquals($expected, $result);
    }

    public function testFindByClientIdWithStatusFilter(): void
    {
        $expected = [
            ['id' => 2, 'client_id' => 5, 'title' => 'Leaking faucet', 'status' => 'resi_se'],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByClientId(5, ['resi_se']);

        $this->assertEquals($expected, $result);
    }

    public function testFindByClientIdWithDateFilter(): void
    {
        $expected = [
            ['id' => 1, 'client_id' => 5, 'title' => 'Broken AC'],
        ];
        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn ($p) => ($p['client_id'] ?? null) === 5 && ($p['date'] ?? null) === '2026-05-05'))
            ->willReturn(true);
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->callback(fn (string $sql) => str_contains($sql, 'AND DATE(r.created_at) = :date')))
            ->willReturn($this->stmtMock);

        $result = $this->repository->findByClientId(5, null, null, '2026-05-05');

        $this->assertEquals($expected, $result);
    }

    public function testFindByClientIdReturnsEmptyArray(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByClientId(5);

        $this->assertEquals([], $result);
    }

    // findByIdForClient

    public function testFindByIdForClientReturnsRequestWhenFound(): void
    {
        $expected = ['id' => 1, 'client_id' => 5, 'title' => 'Broken AC'];
        $this->setupFetchMock($expected);

        $result = $this->repository->findByIdForClient(1, 5);

        $this->assertEquals($expected, $result);
    }

    public function testFindByIdForClientReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByIdForClient(999, 5);

        $this->assertNull($result);
    }

    // findById (admin)

    public function testFindByIdReturnsRequestWhenFound(): void
    {
        $expected = ['id' => 1, 'client_id' => 5, 'title' => 'Broken AC', 'client_display_name' => 'Acme'];
        $this->setupFetchMock($expected);

        $result = $this->repository->findById(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findAllForAdmin

    public function testFindAllForAdminReturnsAllRequests(): void
    {
        $expected = [
            ['id' => 1, 'client_id' => 5, 'title' => 'A'],
            ['id' => 2, 'client_id' => 7, 'title' => 'B'],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findAllForAdmin();

        $this->assertEquals($expected, $result);
    }

    public function testFindAllForAdminWithClientAndStatusFilters(): void
    {
        $expected = [
            ['id' => 1, 'client_id' => 5, 'title' => 'A', 'status' => 'prijato'],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findAllForAdmin(5, 'prijato');

        $this->assertEquals($expected, $result);
    }

    // create

    public function testCreateReturnsNewRequestId(): void
    {
        $this->setupInsertMock(42);

        $newId = $this->repository->create([
            'client_id' => 5,
            'created_by_user_id' => 10,
            'title' => 'Broken AC',
            'category' => 'klima',
            'location_type' => 'office',
            'location_value' => 'Sherlock',
        ]);

        $this->assertEquals(42, $newId);
    }

    public function testCreateWithAllFields(): void
    {
        $this->setupInsertMock(43);

        $newId = $this->repository->create([
            'client_id' => 5,
            'company_id' => 7,
            'created_by_user_id' => 10,
            'title' => 'Broken AC',
            'category' => 'klima',
            'location_type' => 'office',
            'location_value' => 'Sherlock',
            'description' => 'It does not cool',
            'status' => 'prijato',
            'due_date' => '2026-04-15',
        ]);

        $this->assertEquals(43, $newId);
    }

    // update

    public function testUpdateReturnsTrueWhenRowAffected(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['title' => 'Updated']);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsFalseWhenNoFieldsProvided(): void
    {
        $result = $this->repository->update(1, []);

        $this->assertFalse($result);
    }

    public function testUpdateReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(999, ['title' => 'X']);

        $this->assertFalse($result);
    }

    public function testUpdateOnlyAllowsWhitelistedFields(): void
    {
        $this->setupRowCountMock(0);

        // Only non-allowed fields → no update
        $result = $this->repository->update(1, ['client_id' => 99, 'foo' => 'bar']);

        $this->assertFalse($result);
    }

    // updateStatus

    public function testUpdateStatusReturnsTrueWhenChanged(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->updateStatus(1, 'vyreseno');

        $this->assertTrue($result);
    }

    public function testUpdateStatusReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->updateStatus(999, 'vyreseno');

        $this->assertFalse($result);
    }

    // softDelete

    public function testSoftDeleteReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->softDelete(1);

        $this->assertTrue($result);
    }

    public function testSoftDeleteReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->softDelete(999);

        $this->assertFalse($result);
    }

    // findActivity

    public function testFindActivityReturnsTimeline(): void
    {
        $expected = [
            ['id' => 1, 'request_id' => 1, 'message' => 'Created', 'author_type' => 'system', 'is_internal' => 0],
            ['id' => 2, 'request_id' => 1, 'message' => 'Working on it', 'author_type' => 'admin', 'is_internal' => 0],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findActivity(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindActivityWithoutInternalFiltersInternalEntries(): void
    {
        $expected = [
            ['id' => 1, 'request_id' => 1, 'message' => 'Public', 'is_internal' => 0],
        ];
        $this->setupFetchAllMock($expected);

        $result = $this->repository->findActivity(1, false);

        $this->assertEquals($expected, $result);
    }

    // addActivity

    public function testAddActivityReturnsNewId(): void
    {
        $this->setupInsertMock(7);

        $newId = $this->repository->addActivity([
            'request_id' => 1,
            'user_id' => 10,
            'author_type' => 'admin',
            'author_name' => 'Admin User',
            'message' => 'Working on it',
        ]);

        $this->assertEquals(7, $newId);
    }

    public function testAddActivityWithStatusChange(): void
    {
        $this->setupInsertMock(8);

        $newId = $this->repository->addActivity([
            'request_id' => 1,
            'user_id' => 10,
            'author_type' => 'admin',
            'author_name' => 'Admin',
            'message' => 'Status changed',
            'status_change' => 'vyreseno',
        ]);

        $this->assertEquals(8, $newId);
    }

    public function testAddActivityWithInternalFlag(): void
    {
        $this->setupInsertMock(11);

        $newId = $this->repository->addActivity([
            'request_id' => 1,
            'user_id' => 10,
            'author_type' => 'admin',
            'author_name' => 'Admin',
            'message' => 'Internal note',
            'is_internal' => true,
        ]);

        $this->assertEquals(11, $newId);
    }

    public function testAddActivityWithMinimalFields(): void
    {
        $this->setupInsertMock(9);

        $newId = $this->repository->addActivity([
            'request_id' => 1,
            'author_type' => 'system',
        ]);

        $this->assertEquals(9, $newId);
    }

    // countByDayForClient

    public function testCountByDayForClientFormatsRows(): void
    {
        $this->setupFetchAllMock([
            ['d' => '2026-05-06', 's' => 'prijato', 'c' => '2'],
            ['d' => '2026-05-06', 's' => 'resi_se', 'c' => '1'],
            ['d' => '2026-05-07', 's' => 'vyreseno', 'c' => '3'],
        ]);

        $result = $this->repository->countByDayForClient(5, 2026, 5);

        $this->assertEquals([
            ['date' => '2026-05-06', 'status' => 'prijato', 'count' => 2],
            ['date' => '2026-05-06', 'status' => 'resi_se', 'count' => 1],
            ['date' => '2026-05-07', 'status' => 'vyreseno', 'count' => 3],
        ], $result);
    }

    public function testCountByDayForClientReturnsEmptyArray(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->countByDayForClient(5, 2026, 5);

        $this->assertEquals([], $result);
    }

    public function testCountByDayForClientPassesExpectedBindings(): void
    {
        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with([
                'client_id' => 5,
                'excluded_status' => 'zablokovano',
                'year' => 2026,
                'month' => 5,
            ])
            ->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->countByDayForClient(5, 2026, 5);
    }

    public function testCountByDayForClientSqlExcludesZablokovanoAndGroupsByStatus(): void
    {
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('fetchAll')->willReturn([]);
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->callback(function (string $sql) {
                return str_contains($sql, 'status <> :excluded_status')
                    && str_contains($sql, 'GROUP BY DATE(created_at), status')
                    && str_contains($sql, 'deleted_at IS NULL');
            }))
            ->willReturn($this->stmtMock);

        $this->repository->countByDayForClient(5, 2026, 5);
    }
}
