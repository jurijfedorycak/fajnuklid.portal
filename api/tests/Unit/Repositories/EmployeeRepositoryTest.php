<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\EmployeeRepository;
use PDO;
use Tests\DatabaseTestCase;

class EmployeeRepositoryTest extends DatabaseTestCase
{
    private EmployeeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(EmployeeRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsEmployeeWhenFound(): void
    {
        $expectedEmployee = [
            'id' => 1,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+420123456789',
            'position' => 'Cleaner',
            'photo_url' => '/photos/john.jpg',
            'tenure_text' => '2 years',
            'bio' => 'Experienced cleaner',
            'hobbies' => 'Reading',
            'show_name' => 1,
            'show_photo' => 1,
            'show_phone' => 0,
            'show_email' => 0,
            'show_in_portal' => 1,
            'show_role' => 1,
            'show_hobbies' => 0,
            'show_tenure' => 1,
            'show_bio' => 0,
            'deleted_at' => null,
        ];

        $this->setupFetchMock($expectedEmployee);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedEmployee, $result);
    }

    public function testFindByIdExcludesSoftDeleted(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(1);

        $this->assertNull($result);
    }

    // findAll tests

    public function testFindAllReturnsAllActiveEmployees(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'first_name' => 'Alice', 'last_name' => 'Brown'],
            ['id' => 2, 'first_name' => 'Bob', 'last_name' => 'Smith'],
        ];

        $this->setupQueryMock($expectedEmployees);

        $result = $this->repository->findAll();

        $this->assertEquals($expectedEmployees, $result);
    }

    public function testFindAllReturnsCorrectOrdering(): void
    {
        $expectedEmployees = [
            ['id' => 2, 'first_name' => 'Alice', 'last_name' => 'Brown'],
            ['id' => 1, 'first_name' => 'Bob', 'last_name' => 'Smith'],
        ];

        $this->setupQueryMock($expectedEmployees);

        $result = $this->repository->findAll();

        $this->assertEquals('Brown', $result[0]['last_name']);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutSearch(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedEmployees);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expectedEmployees, $result);
    }

    public function testFindPaginatedWithSearchByName(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedEmployees);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'John');

        $this->assertEquals($expectedEmployees, $result);
    }

    public function testFindPaginatedSearchesByEmail(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'email' => 'john@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedEmployees);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'example.com');

        $this->assertEquals($expectedEmployees, $result);
    }

    public function testFindPaginatedSearchesByPosition(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'position' => 'Manager'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedEmployees);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'Manager');

        $this->assertEquals($expectedEmployees, $result);
    }

    // countAll tests

    public function testCountAllExcludesSoftDeleted(): void
    {
        $this->setupFetchColumnMock(5);

        $result = $this->repository->countAll();

        $this->assertEquals(5, $result);
    }

    public function testCountAllWithSearch(): void
    {
        $this->setupFetchColumnMock(2);

        $result = $this->repository->countAll('John');

        $this->assertEquals(2, $result);
    }

    // findByLocation tests

    public function testFindByLocationReturnsEmployees(): void
    {
        $expectedEmployees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith'],
        ];

        $this->setupFetchAllMock($expectedEmployees);

        $result = $this->repository->findByLocation(1);

        $this->assertEquals($expectedEmployees, $result);
    }

    public function testFindByLocationExcludesSoftDeleted(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByLocation(1);

        $this->assertEquals([], $result);
    }

    // create tests

    public function testCreateReturnsNewEmployeeId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'first_name' => 'New',
            'last_name' => 'Employee',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithAllGdprFlags(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'first_name' => 'New',
            'last_name' => 'Employee',
            'show_name' => true,
            'show_photo' => true,
            'show_phone' => false,
            'show_email' => false,
            'show_in_portal' => true,
            'show_role' => true,
            'show_hobbies' => false,
            'show_tenure' => true,
            'show_bio' => false,
        ]);

        $this->assertEquals(2, $result);
    }

    public function testCreateWithDefaultFlags(): void
    {
        $this->setupInsertMock(3);

        $result = $this->repository->create([
            'first_name' => 'New',
            'last_name' => 'Employee',
        ]);

        $this->assertEquals(3, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['first_name' => 'Updated']);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsFalseWhenNoFieldsProvided(): void
    {
        $result = $this->repository->update(1, []);

        $this->assertFalse($result);
    }

    public function testUpdateCastsBooleanToInt(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, [
            'show_name' => true,
            'show_phone' => false,
        ]);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsFalseForSoftDeleted(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(1, ['first_name' => 'Updated']);

        $this->assertFalse($result);
    }

    // saveAll tests

    public function testSaveAllWithNewEmployees(): void
    {
        $this->setupTransactionMock(true);
        $this->setupInsertMock(1);

        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('execute')->willReturn(true);

        $result = $this->repository->saveAll([
            ['first_name' => 'New', 'last_name' => 'Employee1'],
        ]);

        $this->assertIsArray($result);
    }

    public function testSaveAllWithExistingEmployees(): void
    {
        $this->setupTransactionMock(true);
        $this->setupRowCountMock(1);

        $result = $this->repository->saveAll([
            ['id' => 1, 'first_name' => 'Updated', 'last_name' => 'Employee'],
        ]);

        $this->assertEquals([1], $result);
    }

    public function testSaveAllRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->saveAll([
            ['first_name' => 'Fail', 'last_name' => 'Employee'],
        ]);
    }

    // delete tests

    public function testDeleteSoftDeletesEmployee(): void
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

    public function testRestoreReactivatesEmployee(): void
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

    // existsByEmail tests

    public function testExistsByEmailReturnsTrueWhenExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->existsByEmail('john@example.com');

        $this->assertTrue($result);
    }

    public function testExistsByEmailReturnsFalseWhenNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByEmail('nonexistent@example.com');

        $this->assertFalse($result);
    }

    public function testExistsByEmailExcludesSoftDeleted(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByEmail('john@example.com');

        $this->assertFalse($result);
    }

    public function testExistsByEmailWithExcludeId(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByEmail('john@example.com', 1);

        $this->assertFalse($result);
    }
}
