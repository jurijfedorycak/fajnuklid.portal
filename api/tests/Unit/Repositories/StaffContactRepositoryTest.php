<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\StaffContactRepository;
use PDO;
use Tests\DatabaseTestCase;

class StaffContactRepositoryTest extends DatabaseTestCase
{
    private StaffContactRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(StaffContactRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsContactWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'name' => 'John Doe',
            'position' => 'Support Manager',
            'phone' => '+420123456789',
            'email' => 'john@fajnuklid.cz',
            'user_id' => null,
            'photo_url' => '/photos/john.jpg',
            'sort_order' => 0,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'deleted_at' => null,
            'login_portal_enabled' => null,
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findById(1);

        $this->assertEquals($expected, $result);
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

    // findAll tests

    public function testFindAllReturnsAllActiveContactsOrderedBySortOrder(): void
    {
        $expected = [
            ['id' => 1, 'name' => 'First Contact', 'sort_order' => 0],
            ['id' => 2, 'name' => 'Second Contact', 'sort_order' => 1],
        ];

        $this->setupQueryMock($expected);

        $result = $this->repository->findAll();

        $this->assertEquals($expected, $result);
        $this->assertEquals(0, $result[0]['sort_order']);
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
            ['id' => 1, 'name' => 'John Doe', 'position' => 'Manager'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'manager');

        $this->assertEquals($expected, $result);
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

        $result = $this->repository->countAll('support');

        $this->assertEquals(2, $result);
    }

    public function testCountAllExcludesSoftDeleted(): void
    {
        $this->setupFetchColumnMock(3);

        $result = $this->repository->countAll();

        $this->assertEquals(3, $result);
    }

    // create tests

    public function testCreateReturnsNewId(): void
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
            'position' => 'Support Agent',
            'phone' => '+420123456789',
            'email' => 'new@fajnuklid.cz',
            'photo_url' => '/photos/new.jpg',
            'sort_order' => 5,
        ]);

        $this->assertEquals(2, $result);
    }

    public function testCreateWithDefaultSortOrder(): void
    {
        $this->setupInsertMock(3);

        $result = $this->repository->create([
            'name' => 'Contact Without Sort',
        ]);

        $this->assertEquals(3, $result);
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

    public function testUpdateReturnsFalseForSoftDeleted(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(1, ['name' => 'Updated']);

        $this->assertFalse($result);
    }

    public function testUpdateReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(999, ['name' => 'Updated']);

        $this->assertFalse($result);
    }

    // delete (soft delete) tests

    public function testDeleteSoftDeletesContact(): void
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

    public function testDeleteReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->delete(999);

        $this->assertFalse($result);
    }

    // restore tests

    public function testRestoreReactivatesContact(): void
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

    // getMaxSortOrder tests

    public function testGetMaxSortOrderReturnsMaxValue(): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn(10);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getMaxSortOrder();

        $this->assertEquals(10, $result);
    }

    public function testGetMaxSortOrderReturnsZeroWhenNoContacts(): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn(false);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getMaxSortOrder();

        $this->assertEquals(0, $result);
    }

    // reorder tests

    public function testReorderCommitsTransaction(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $stmt = $this->createStatementMock();
        $stmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')->willReturn($stmt);

        $result = $this->repository->reorder([1, 2, 3]);

        $this->assertTrue($result);
    }

    public function testReorderUpdatesSortOrderForEachId(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $stmt = $this->createStatementMock();
        $stmt->expects($this->exactly(3))
            ->method('execute')
            ->willReturn(true);

        $this->pdoMock->method('prepare')->willReturn($stmt);

        $result = $this->repository->reorder([5, 3, 1]);

        $this->assertTrue($result);
    }

    public function testReorderRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->reorder([1, 2, 3]);
    }

    public function testReorderHandlesEmptyArray(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $stmt = $this->createStatementMock();
        $stmt->expects($this->never())->method('execute');

        $this->pdoMock->method('prepare')->willReturn($stmt);

        $result = $this->repository->reorder([]);

        $this->assertTrue($result);
    }

    // setUserId tests

    public function testSetUserIdAssignsLogin(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->setUserId(1, 42);

        $this->assertTrue($result);
    }

    public function testSetUserIdAcceptsNullToClear(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->setUserId(1, null);

        $this->assertTrue($result);
    }

    public function testSetUserIdReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->setUserId(999, 42);

        $this->assertFalse($result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsRowWhenLinked(): void
    {
        $expected = [
            'id' => 1,
            'name' => 'Linked Staff',
            'user_id' => 7,
            'login_portal_enabled' => 1,
        ];
        $this->setupFetchMock($expected);

        $result = $this->repository->findByUserId(7);

        $this->assertEquals($expected, $result);
    }

    public function testFindByUserIdReturnsNullWhenNoMatch(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByUserId(999);

        $this->assertNull($result);
    }

    // findByEmail tests

    public function testFindByEmailReturnsActiveRow(): void
    {
        $expected = [
            'id' => 2,
            'email' => 'staff@fajnuklid.cz',
            'user_id' => null,
            'login_portal_enabled' => null,
        ];
        $this->setupFetchMock($expected);

        $result = $this->repository->findByEmail('staff@fajnuklid.cz');

        $this->assertEquals($expected, $result);
    }

    public function testFindByEmailReturnsNullWhenNoMatch(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByEmail('nope@fajnuklid.cz');

        $this->assertNull($result);
    }
}
