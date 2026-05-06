<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\ClientRepository;
use PDO;
use Tests\DatabaseTestCase;

class ClientRepositoryTest extends DatabaseTestCase
{
    private ClientRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(ClientRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsClientWhenFound(): void
    {
        $expectedClient = [
            'id' => 1,
            'client_id' => 'CLT001',
            'display_name' => 'Test Client',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'deleted_at' => null,
        ];

        $this->setupFetchMock($expectedClient);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedClient, $result);
    }

    public function testFindByIdExcludesSoftDeleted(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(1);

        $this->assertNull($result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findByClientId tests

    public function testFindByClientIdReturnsClientWhenFound(): void
    {
        $expectedClient = [
            'id' => 1,
            'client_id' => 'CLT001',
            'display_name' => 'Test Client',
        ];

        $this->setupFetchMock($expectedClient);

        $result = $this->repository->findByClientId('CLT001');

        $this->assertEquals($expectedClient, $result);
    }

    public function testFindByClientIdExcludesSoftDeleted(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByClientId('CLT001');

        $this->assertNull($result);
    }

    // findAll tests

    public function testFindAllReturnsAllActiveClients(): void
    {
        $expectedClients = [
            ['id' => 1, 'client_id' => 'CLT001', 'display_name' => 'Client A'],
            ['id' => 2, 'client_id' => 'CLT002', 'display_name' => 'Client B'],
        ];

        $this->setupQueryMock($expectedClients);

        $result = $this->repository->findAll();

        $this->assertEquals($expectedClients, $result);
    }

    public function testFindAllExcludesSoftDeleted(): void
    {
        $this->setupQueryMock([]);

        $result = $this->repository->findAll();

        $this->assertEquals([], $result);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutSearch(): void
    {
        $expectedClients = [
            ['id' => 1, 'display_name' => 'Client A'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedClients);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expectedClients, $result);
    }

    public function testFindPaginatedWithSearch(): void
    {
        $expectedClients = [
            ['id' => 1, 'display_name' => 'Test Client'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedClients);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'Test');

        $this->assertEquals($expectedClients, $result);
    }

    // countAll tests

    public function testCountAllWithoutSearch(): void
    {
        $this->setupFetchColumnMock(5);

        $result = $this->repository->countAll();

        $this->assertEquals(5, $result);
    }

    public function testCountAllWithSearch(): void
    {
        $this->setupFetchColumnMock(2);

        $result = $this->repository->countAll('test');

        $this->assertEquals(2, $result);
    }

    public function testCountAllExcludesSoftDeleted(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->countAll();

        $this->assertEquals(0, $result);
    }

    // create tests

    public function testCreateReturnsNewClientId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'client_id' => 'CLT003',
            'display_name' => 'New Client',
        ]);

        $this->assertEquals(1, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['display_name' => 'Updated Name']);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsFalseWhenNoFieldsProvided(): void
    {
        $result = $this->repository->update(1, []);

        $this->assertFalse($result);
    }

    public function testUpdateReturnsFalseForSoftDeleted(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(1, ['display_name' => 'Updated']);

        $this->assertFalse($result);
    }

    // delete (soft delete) tests

    public function testDeleteSoftDeletesClient(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->delete(1);

        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenAlreadyDeleted(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->delete(1);

        $this->assertFalse($result);
    }

    // restore tests

    public function testRestoreReactivatesClient(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->restore(1);

        $this->assertTrue($result);
    }

    public function testRestoreReturnsFalseWhenAlreadyActive(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->restore(1);

        $this->assertFalse($result);
    }

    // existsByClientId tests

    public function testExistsByClientIdReturnsTrueWhenExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->existsByClientId('CLT001');

        $this->assertTrue($result);
    }

    public function testExistsByClientIdReturnsFalseWhenNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByClientId('NONEXISTENT');

        $this->assertFalse($result);
    }

    public function testExistsByClientIdExcludesSoftDeleted(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByClientId('CLT001');

        $this->assertFalse($result);
    }

    public function testExistsByClientIdWithExcludeId(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByClientId('CLT001', 1);

        $this->assertFalse($result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsClientWhenUserIsLinked(): void
    {
        $expected = [
            'id' => 1,
            'client_id' => 'CLT001',
            'display_name' => 'Demo Client',
            'is_demo' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'deleted_at' => null,
        ];
        $this->setupFetchMock($expected);

        $result = $this->repository->findByUserId(42);

        $this->assertEquals($expected, $result);
    }

    public function testFindByUserIdReturnsNullWhenUserNotLinked(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByUserId(999);

        $this->assertNull($result);
    }

    // is_demo binding tests

    public function testCreatePersistsIsDemoTrueAsOne(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 'CLT003',
            'display_name' => 'New Client',
            'is_demo' => true,
        ]);

        $this->assertSame(1, $captured['is_demo']);
    }

    public function testCreateDefaultsIsDemoToZeroWhenOmitted(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 'CLT003',
            'display_name' => 'New Client',
        ]);

        $this->assertSame(0, $captured['is_demo']);
    }

    public function testUpdatePersistsIsDemoFlagWhenProvided(): void
    {
        $captured = null;
        $sqlSeen = '';
        $this->pdoMock->method('prepare')
            ->willReturnCallback(function ($sql) use (&$sqlSeen) {
                $sqlSeen = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });
        $this->stmtMock->method('rowCount')->willReturn(1);

        $result = $this->repository->update(1, ['is_demo' => true]);

        $this->assertTrue($result);
        $this->assertStringContainsString('is_demo = :is_demo', $sqlSeen);
        $this->assertSame(1, $captured['is_demo']);
    }

    public function testUpdatePersistsIsDemoFalseAsZero(): void
    {
        $captured = null;
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });
        $this->stmtMock->method('rowCount')->willReturn(1);

        // Explicit false must persist (array_key_exists, not isset, in the repo)
        // so admins can flip a demo flag back off.
        $this->repository->update(1, ['is_demo' => false]);

        $this->assertSame(0, $captured['is_demo']);
    }

    public function testUpdateOmitsIsDemoFromSqlWhenNotProvided(): void
    {
        $sqlSeen = '';
        $this->pdoMock->method('prepare')
            ->willReturnCallback(function ($sql) use (&$sqlSeen) {
                $sqlSeen = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('rowCount')->willReturn(1);

        $this->repository->update(1, ['display_name' => 'Renamed']);

        $this->assertStringNotContainsString('is_demo', $sqlSeen);
    }
}
