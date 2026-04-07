<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\ClientContactRepository;
use PDO;
use Tests\DatabaseTestCase;

class ClientContactRepositoryTest extends DatabaseTestCase
{
    private ClientContactRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(ClientContactRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsContactWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'name' => 'John Doe',
            'position' => 'Manager',
            'phone' => '+420123456789',
            'email' => 'john@example.com',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
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

    // findAll tests

    public function testFindAllReturnsAllContacts(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Alice Brown'],
            ['id' => 2, 'name' => 'Bob Smith'],
        ];

        $this->setupQueryMock($expected);

        $result = $this->repository->findAll();

        $this->assertEquals($expected, $result);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutSearch(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'John Doe'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expected, $result);
    }

    public function testFindPaginatedWithSearch(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'john');

        $this->assertEquals($expected, $result);
    }

    // countAll tests

    public function testCountAllWithoutSearch(): void
    {
        $this->setupFetchColumnMock(10);

        $result = $this->repository->countAll();

        $this->assertEquals(10, $result);
    }

    public function testCountAllWithSearch(): void
    {
        $this->setupFetchColumnMock(2);

        $result = $this->repository->countAll('manager');

        $this->assertEquals(2, $result);
    }

    // findByCompanyId tests

    public function testFindByCompanyIdReturnsContactsOrderedByPrimary(): void
    {
        $expected = [
            ['id' => 2, 'name' => 'Primary Contact', 'is_primary' => 1],
            ['id' => 1, 'name' => 'Secondary Contact', 'is_primary' => 0],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals($expected, $result);
        $this->assertEquals(1, $result[0]['is_primary']);
    }

    // create tests

    public function testCreateReturnsNewContactId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'name' => 'New Contact',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithAllFields(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'name' => 'New Contact',
            'position' => 'Director',
            'phone' => '+420123456789',
            'email' => 'contact@example.com',
        ]);

        $this->assertEquals(2, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['name' => 'Updated Name']);

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

    // existsByEmail tests

    public function testExistsByEmailReturnsTrueWhenExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->existsByEmail('test@example.com');

        $this->assertTrue($result);
    }

    public function testExistsByEmailReturnsFalseWhenNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByEmail('nonexistent@example.com');

        $this->assertFalse($result);
    }

    public function testExistsByEmailWithExcludeId(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByEmail('test@example.com', 1);

        $this->assertFalse($result);
    }

    // findUnassigned tests

    public function testFindUnassignedReturnsOrphanContacts(): void
    {
        $expected = [
            ['id' => 5, 'name' => 'Orphan Contact'],
        ];

        $this->setupQueryMock($expected);

        $result = $this->repository->findUnassigned();

        $this->assertEquals($expected, $result);
    }

    // findByClientId tests

    public function testFindByClientIdReturnsContacts(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'Contact A', 'company_id' => 1],
            ['id' => 2, 'name' => 'Contact B', 'company_id' => 2],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByClientId(1);

        $this->assertEquals($expected, $result);
    }

    // deleteByCompanyIds tests

    public function testDeleteByCompanyIdsReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByCompanyIds([1, 2]);

        $this->assertEquals(3, $result);
    }

    public function testDeleteByCompanyIdsReturnsZeroWhenEmptyArray(): void
    {
        $result = $this->repository->deleteByCompanyIds([]);

        $this->assertEquals(0, $result);
    }

    public function testDeleteByCompanyIdsDeletesOrphansOnly(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByCompanyIds([1]);

        $this->assertEquals(1, $result);
    }
}
