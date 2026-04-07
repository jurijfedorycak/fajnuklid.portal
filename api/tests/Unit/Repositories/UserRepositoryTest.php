<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\UserRepository;
use PDO;
use Tests\DatabaseTestCase;

class UserRepositoryTest extends DatabaseTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(UserRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsUserWhenFound(): void
    {
        $expectedUser = [
            'id' => 1,
            'email' => 'test@example.com',
            'password_hash' => 'hash123',
            'portal_enabled' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ];

        $this->setupFetchMock($expectedUser);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedUser, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findByEmail tests

    public function testFindByEmailReturnsUserWhenFound(): void
    {
        $expectedUser = [
            'id' => 1,
            'email' => 'test@example.com',
            'password_hash' => 'hash123',
            'portal_enabled' => 1,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ];

        $this->setupFetchMock($expectedUser);

        $result = $this->repository->findByEmail('test@example.com');

        $this->assertEquals($expectedUser, $result);
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByEmail('nonexistent@example.com');

        $this->assertNull($result);
    }

    // updateLastLogin tests

    public function testUpdateLastLoginExecutesUpdate(): void
    {
        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with(['id' => 1])
            ->willReturn(true);

        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->updateLastLogin(1);
    }

    // updatePassword tests

    public function testUpdatePasswordReturnsTrueWhenUserUpdated(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->updatePassword(1, 'newHash123');

        $this->assertTrue($result);
    }

    public function testUpdatePasswordReturnsFalseWhenUserNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->updatePassword(999, 'newHash123');

        $this->assertFalse($result);
    }

    // findAll tests

    public function testFindAllReturnsAllUsers(): void
    {
        $expectedUsers = [
            ['id' => 1, 'email' => 'a@example.com', 'portal_enabled' => 1],
            ['id' => 2, 'email' => 'b@example.com', 'portal_enabled' => 0],
        ];

        $this->setupQueryMock($expectedUsers);

        $result = $this->repository->findAll();

        $this->assertEquals($expectedUsers, $result);
    }

    public function testFindAllReturnsEmptyArrayWhenNoUsers(): void
    {
        $this->setupQueryMock([]);

        $result = $this->repository->findAll();

        $this->assertEquals([], $result);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutSearch(): void
    {
        $expectedUsers = [
            ['id' => 1, 'email' => 'a@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedUsers);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expectedUsers, $result);
    }

    public function testFindPaginatedWithSearch(): void
    {
        $expectedUsers = [
            ['id' => 1, 'email' => 'test@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedUsers);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'test');

        $this->assertEquals($expectedUsers, $result);
    }

    public function testFindPaginatedWithOffset(): void
    {
        $expectedUsers = [
            ['id' => 3, 'email' => 'c@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedUsers);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 20);

        $this->assertEquals($expectedUsers, $result);
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

    public function testCountAllReturnsZeroWhenNoUsers(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->countAll();

        $this->assertEquals(0, $result);
    }

    // create tests

    public function testCreateReturnsNewUserId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'email' => 'new@example.com',
            'password_hash' => 'hash123',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithPortalEnabledFlag(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'email' => 'new@example.com',
            'password_hash' => 'hash123',
            'portal_enabled' => false,
        ]);

        $this->assertEquals(2, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenFieldsModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['email' => 'updated@example.com']);

        $this->assertTrue($result);
    }

    public function testUpdateReturnsFalseWhenNoFieldsProvided(): void
    {
        $result = $this->repository->update(1, []);

        $this->assertFalse($result);
    }

    public function testUpdateReturnsFalseWhenUserNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->update(999, ['email' => 'updated@example.com']);

        $this->assertFalse($result);
    }

    public function testUpdateWithMultipleFields(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, [
            'email' => 'updated@example.com',
            'password_hash' => 'newhash',
            'portal_enabled' => false,
        ]);

        $this->assertTrue($result);
    }

    // delete tests

    public function testDeleteReturnsTrueWhenUserDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->delete(1);

        $this->assertTrue($result);
    }

    public function testDeleteReturnsFalseWhenUserNotFound(): void
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

    public function testExistsByEmailReturnsTrueWhenExistsWithDifferentId(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->existsByEmail('test@example.com', 2);

        $this->assertTrue($result);
    }
}
