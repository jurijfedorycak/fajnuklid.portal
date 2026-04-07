<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\ClientEmployeeRepository;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

class ClientEmployeeRepositoryTest extends DatabaseTestCase
{
    private ClientEmployeeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(ClientEmployeeRepository::class);
    }

    // findByClientId tests

    public function testFindByClientIdReturnsEmployees(): void
    {
        $expected = [
            [
                'id' => 1,
                'client_id' => 1,
                'employee_id' => 1,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'position' => 'Cleaner',
            ],
            [
                'id' => 2,
                'client_id' => 1,
                'employee_id' => 2,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'position' => 'Supervisor',
            ],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByClientId(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindByClientIdReturnsEmptyArrayWhenNoEmployees(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByClientId(999);

        $this->assertEquals([], $result);
    }

    // findByEmployeeId tests

    public function testFindByEmployeeIdReturnsClients(): void
    {
        $expected = [
            [
                'id' => 1,
                'client_id' => 1,
                'employee_id' => 1,
                'client_code' => 'CLI-001',
                'client_name' => 'Test Client',
            ],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByEmployeeId(1);

        $this->assertEquals($expected, $result);
    }

    // exists tests

    public function testExistsReturnsTrueWhenRelationshipExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->exists(1, 1);

        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseWhenRelationshipDoesNotExist(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->exists(1, 999);

        $this->assertFalse($result);
    }

    // create tests

    public function testCreateReturnsNewId(): void
    {
        $this->setupInsertMock(5);

        $result = $this->repository->create(1, 1);

        $this->assertEquals(5, $result);
    }

    // delete tests

    public function testDeleteReturnsTrueWhenRowDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->delete(1);

        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenRowNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->delete(999);

        $this->assertFalse($result);
    }

    // deleteByClientAndEmployee tests

    public function testDeleteByClientAndEmployeeReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByClientAndEmployee(1, 1);

        $this->assertTrue($result);
    }

    public function testDeleteByClientAndEmployeeReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteByClientAndEmployee(999, 999);

        $this->assertFalse($result);
    }

    // deleteByClientId tests

    public function testDeleteByClientIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByClientId(1);

        $this->assertEquals(3, $result);
    }

    // deleteByEmployeeId tests

    public function testDeleteByEmployeeIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(2);

        $result = $this->repository->deleteByEmployeeId(1);

        $this->assertEquals(2, $result);
    }

    // syncClientEmployees tests

    public function testSyncClientEmployeesDeletesAndInsertsNew(): void
    {
        $this->setupTransactionMock();
        $this->setupRowCountMock(2); // For delete

        $this->repository->syncClientEmployees(1, [1, 2, 3]);

        // Test passes if no exception thrown
        $this->assertTrue(true);
    }

    public function testSyncClientEmployeesThrowsOnInvalidEmployeeId(): void
    {
        $this->setupTransactionMock();
        $this->setupRowCountMock(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid employee ID provided');

        $this->repository->syncClientEmployees(1, [1, 'invalid', 3]);
    }

    public function testSyncClientEmployeesThrowsOnNegativeEmployeeId(): void
    {
        $this->setupTransactionMock();
        $this->setupRowCountMock(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid employee ID provided');

        $this->repository->syncClientEmployees(1, [1, -5, 3]);
    }

    public function testSyncClientEmployeesThrowsOnZeroEmployeeId(): void
    {
        $this->setupTransactionMock();
        $this->setupRowCountMock(0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid employee ID provided');

        $this->repository->syncClientEmployees(1, [1, 0, 3]);
    }

    // getEmployeeIdsByClient tests

    public function testGetEmployeeIdsByClientReturnsIds(): void
    {
        $this->setupFetchAllMock([
            ['employee_id' => 1],
            ['employee_id' => 2],
            ['employee_id' => 3],
        ]);

        $result = $this->repository->getEmployeeIdsByClient(1);

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testGetEmployeeIdsByClientReturnsEmptyArrayWhenNone(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->getEmployeeIdsByClient(999);

        $this->assertEquals([], $result);
    }

    // getClientIdsByEmployee tests

    public function testGetClientIdsByEmployeeReturnsIds(): void
    {
        $this->setupFetchAllMock([
            ['client_id' => 1],
            ['client_id' => 2],
        ]);

        $result = $this->repository->getClientIdsByEmployee(1);

        $this->assertEquals([1, 2], $result);
    }

    public function testGetClientIdsByEmployeeReturnsEmptyArrayWhenNone(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->getClientIdsByEmployee(999);

        $this->assertEquals([], $result);
    }
}
