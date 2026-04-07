<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\LocationRepository;
use PDO;
use Tests\DatabaseTestCase;

class LocationRepositoryTest extends DatabaseTestCase
{
    private LocationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(LocationRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsLocationWithCompanyData(): void
    {
        $expectedLocation = [
            'id' => 1,
            'company_id' => 1,
            'name' => 'Main Office',
            'address' => '123 Test St',
            'latitude' => 50.0755,
            'longitude' => 14.4378,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'company_name' => 'Test Company',
            'company_registration_number' => '12345678',
        ];

        $this->setupFetchMock($expectedLocation);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedLocation, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findByCompanyId tests

    public function testFindByCompanyIdReturnsLocations(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A', 'company_id' => 1],
            ['id' => 2, 'name' => 'Office B', 'company_id' => 1],
        ];

        $this->setupFetchAllMock($expectedLocations);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals($expectedLocations, $result);
    }

    public function testFindByCompanyIdReturnsEmptyArrayWhenNone(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByCompanyId(999);

        $this->assertEquals([], $result);
    }

    // findAll tests

    public function testFindAllReturnsAllLocationsWithCompanyNames(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A', 'company_name' => 'Company 1'],
            ['id' => 2, 'name' => 'Office B', 'company_name' => 'Company 2'],
        ];

        $this->setupQueryMock($expectedLocations);

        $result = $this->repository->findAll();

        $this->assertEquals($expectedLocations, $result);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutFilters(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedLocations);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expectedLocations, $result);
    }

    public function testFindPaginatedWithSearchByName(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Test Office'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedLocations);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'Test');

        $this->assertEquals($expectedLocations, $result);
    }

    public function testFindPaginatedWithCompanyFilter(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A', 'company_id' => 5],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedLocations);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, null, 5);

        $this->assertEquals($expectedLocations, $result);
    }

    // countAll tests

    public function testCountAllWithoutFilters(): void
    {
        $this->setupFetchColumnMock(10);

        $result = $this->repository->countAll();

        $this->assertEquals(10, $result);
    }

    public function testCountAllWithSearch(): void
    {
        $this->setupFetchColumnMock(3);

        $result = $this->repository->countAll('office');

        $this->assertEquals(3, $result);
    }

    public function testCountAllWithCompanyFilter(): void
    {
        $this->setupFetchColumnMock(2);

        $result = $this->repository->countAll(null, 1);

        $this->assertEquals(2, $result);
    }

    // findByClientId tests

    public function testFindByClientIdReturnsLocations(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A', 'company_name' => 'Company 1'],
        ];

        $this->setupFetchAllMock($expectedLocations);

        $result = $this->repository->findByClientId(1);

        $this->assertEquals($expectedLocations, $result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsLocations(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A'],
            ['id' => 2, 'name' => 'Office B'],
        ];

        $this->setupFetchAllMock($expectedLocations);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expectedLocations, $result);
    }

    // create tests

    public function testCreateReturnsNewLocationId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'company_id' => 1,
            'name' => 'New Office',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithCoordinates(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'company_id' => 1,
            'name' => 'New Office',
            'address' => '123 Test St',
            'latitude' => 50.0755,
            'longitude' => 14.4378,
        ]);

        $this->assertEquals(2, $result);
    }

    public function testCreateWithoutCoordinates(): void
    {
        $this->setupInsertMock(3);

        $result = $this->repository->create([
            'company_id' => 1,
            'name' => 'New Office',
            'address' => '123 Test St',
        ]);

        $this->assertEquals(3, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['name' => 'Updated Office']);

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

        $result = $this->repository->update(999, ['name' => 'Updated']);

        $this->assertFalse($result);
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

    // deleteByCompanyId tests

    public function testDeleteByCompanyIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByCompanyId(1);

        $this->assertEquals(3, $result);
    }

    public function testDeleteByCompanyIdReturnsZeroWhenNone(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteByCompanyId(999);

        $this->assertEquals(0, $result);
    }

    // findWithCoordinates tests

    public function testFindWithCoordinatesFiltersNulls(): void
    {
        $expectedLocations = [
            ['id' => 1, 'name' => 'Office A', 'latitude' => 50.0, 'longitude' => 14.0],
        ];

        $this->setupQueryMock($expectedLocations);

        $result = $this->repository->findWithCoordinates();

        $this->assertEquals($expectedLocations, $result);
    }

    // belongsToUser tests

    public function testBelongsToUserReturnsTrueWhenUserHasAccess(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->belongsToUser(1, 1);

        $this->assertTrue($result);
    }

    public function testBelongsToUserReturnsFalseWhenNoAccess(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->belongsToUser(1, 999);

        $this->assertFalse($result);
    }
}
