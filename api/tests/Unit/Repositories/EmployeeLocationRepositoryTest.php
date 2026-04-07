<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\EmployeeLocationRepository;
use InvalidArgumentException;
use PDO;
use Tests\DatabaseTestCase;

class EmployeeLocationRepositoryTest extends DatabaseTestCase
{
    private EmployeeLocationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(EmployeeLocationRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsJoinedData(): void
    {
        $expected = [
            'id' => 1,
            'employee_id' => 1,
            'location_id' => 1,
            'employee_first_name' => 'John',
            'employee_last_name' => 'Doe',
            'location_name' => 'Main Office',
            'location_address' => '123 Test St',
        ];

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

    // findByEmployeeId tests

    public function testFindByEmployeeIdReturnsLocations(): void
    {
        $expected = [
            ['id' => 1, 'location_id' => 1, 'location_name' => 'Office A'],
            ['id' => 2, 'location_id' => 2, 'location_name' => 'Office B'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByEmployeeId(1);

        $this->assertEquals($expected, $result);
    }

    // findByLocationId tests

    public function testFindByLocationIdReturnsEmployees(): void
    {
        $expected = [
            ['id' => 1, 'employee_id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'employee_id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByLocationId(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindByLocationIdExcludesSoftDeleted(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByLocationId(1);

        $this->assertEquals([], $result);
    }

    // exists tests

    public function testExistsReturnsTrueWhenRelationExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->exists(1, 1);

        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseWhenRelationNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->exists(1, 999);

        $this->assertFalse($result);
    }

    // create tests

    public function testCreateReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create(1, 1);

        $this->assertEquals(1, $result);
    }

    // delete tests

    public function testDeleteReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->delete(1);

        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->delete(999);

        $this->assertFalse($result);
    }

    // deleteByEmployeeAndLocation tests

    public function testDeleteByEmployeeAndLocationReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByEmployeeAndLocation(1, 1);

        $this->assertTrue($result);
    }

    public function testDeleteByEmployeeAndLocationReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteByEmployeeAndLocation(999, 999);

        $this->assertFalse($result);
    }

    // deleteByEmployeeId tests

    public function testDeleteByEmployeeIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByEmployeeId(1);

        $this->assertEquals(3, $result);
    }

    // deleteByLocationId tests

    public function testDeleteByLocationIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(5);

        $result = $this->repository->deleteByLocationId(1);

        $this->assertEquals(5, $result);
    }

    // syncEmployeeLocations tests

    public function testSyncEmployeeLocationsCommitsTransaction(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->syncEmployeeLocations(1, [1]);
    }

    public function testSyncEmployeeLocationsRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->syncEmployeeLocations(1, [1]);
    }

    public function testSyncEmployeeLocationsValidatesLocationIds(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid location ID provided');

        $this->repository->syncEmployeeLocations(1, [-1]);
    }

    // syncLocationEmployees tests

    public function testSyncLocationEmployeesCommitsTransaction(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->syncLocationEmployees(1, [1]);
    }

    public function testSyncLocationEmployeesValidatesEmployeeIds(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid employee ID provided');

        $this->repository->syncLocationEmployees(1, [0]);
    }

    // getLocationIdsByEmployee tests

    public function testGetLocationIdsByEmployeeReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['location_id' => 1],
            ['location_id' => 2],
            ['location_id' => 3],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getLocationIdsByEmployee(1);

        $this->assertEquals([1, 2, 3], $result);
    }

    // getEmployeeIdsByLocation tests

    public function testGetEmployeeIdsByLocationReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['employee_id' => 1],
            ['employee_id' => 2],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getEmployeeIdsByLocation(1);

        $this->assertEquals([1, 2], $result);
    }

    // findEmployeesByClientId tests

    public function testFindEmployeesByClientIdReturnsDistinctEmployees(): void
    {
        $expected = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findEmployeesByClientId(1);

        $this->assertEquals($expected, $result);
    }

    // getLocationIdsByClientEmployees tests

    public function testGetLocationIdsByClientEmployeesReturnsMapStructure(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['employee_id' => '1', 'location_id' => '1'],
            ['employee_id' => '1', 'location_id' => '2'],
            ['employee_id' => '2', 'location_id' => '1'],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getLocationIdsByClientEmployees(1);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertEquals([1, 2], $result[1]);
        $this->assertEquals([1], $result[2]);
    }

    public function testGetLocationIdsByClientEmployeesReturnsEmptyArrayWhenNoEmployees(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getLocationIdsByClientEmployees(999);

        $this->assertEquals([], $result);
    }
}
