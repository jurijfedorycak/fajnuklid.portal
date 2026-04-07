<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\PasswordResetTokenRepository;
use PDO;
use Tests\DatabaseTestCase;

class PasswordResetTokenRepositoryTest extends DatabaseTestCase
{
    private PasswordResetTokenRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(PasswordResetTokenRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsTokenWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'user_id' => 1,
            'token' => 'abc123token',
            'expires_at' => '2024-01-15 12:00:00',
            'used_at' => null,
            'created_at' => '2024-01-01 12:00:00',
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

    // findByToken tests

    public function testFindByTokenReturnsTokenWithUserEmail(): void
    {
        $expected = [
            'id' => 1,
            'user_id' => 1,
            'token' => 'abc123token',
            'expires_at' => '2024-01-15 12:00:00',
            'used_at' => null,
            'created_at' => '2024-01-01 12:00:00',
            'user_email' => 'user@example.com',
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findByToken('abc123token');

        $this->assertEquals($expected, $result);
    }

    public function testFindByTokenReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByToken('nonexistent');

        $this->assertNull($result);
    }

    // findValidToken tests

    public function testFindValidTokenReturnsTokenWhenNotUsedAndNotExpired(): void
    {
        $expected = [
            'id' => 1,
            'token' => 'validtoken',
            'used_at' => null,
            'expires_at' => '2025-01-15 12:00:00',
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findValidToken('validtoken');

        $this->assertEquals($expected, $result);
    }

    public function testFindValidTokenReturnsNullWhenUsed(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findValidToken('usedtoken');

        $this->assertNull($result);
    }

    public function testFindValidTokenReturnsNullWhenExpired(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findValidToken('expiredtoken');

        $this->assertNull($result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsTokensOrderedByDateDesc(): void
    {
        $expected = [
            ['id' => 2, 'created_at' => '2024-01-15 12:00:00'],
            ['id' => 1, 'created_at' => '2024-01-01 12:00:00'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expected, $result);
    }

    // create tests

    public function testCreateReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'user_id' => 1,
            'token' => 'newtoken123',
            'expires_at' => '2024-01-15 12:00:00',
        ]);

        $this->assertEquals(1, $result);
    }

    // markAsUsed tests

    public function testMarkAsUsedReturnsTrueWhenMarked(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->markAsUsed(1);

        $this->assertTrue($result);
    }

    public function testMarkAsUsedReturnsFalseWhenAlreadyUsed(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->markAsUsed(1);

        $this->assertFalse($result);
    }

    // markAsUsedByToken tests

    public function testMarkAsUsedByTokenReturnsTrueWhenMarked(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->markAsUsedByToken('validtoken');

        $this->assertTrue($result);
    }

    public function testMarkAsUsedByTokenReturnsFalseWhenAlreadyUsed(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->markAsUsedByToken('usedtoken');

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

    // deleteByUserId tests

    public function testDeleteByUserIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByUserId(1);

        $this->assertEquals(3, $result);
    }

    // deleteExpired tests

    public function testDeleteExpiredReturnsDeletedCount(): void
    {
        $this->stmtMock->method('rowCount')->willReturn(5);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->deleteExpired();

        $this->assertEquals(5, $result);
    }

    public function testDeleteExpiredReturnsZeroWhenNoneExpired(): void
    {
        $this->stmtMock->method('rowCount')->willReturn(0);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->deleteExpired();

        $this->assertEquals(0, $result);
    }

    // invalidatePreviousTokens tests

    public function testInvalidatePreviousTokensReturnsUpdatedCount(): void
    {
        $this->setupRowCountMock(2);

        $result = $this->repository->invalidatePreviousTokens(1);

        $this->assertEquals(2, $result);
    }

    public function testInvalidatePreviousTokensReturnsZeroWhenNoTokens(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->invalidatePreviousTokens(999);

        $this->assertEquals(0, $result);
    }

    // hasValidToken tests

    public function testHasValidTokenReturnsTrueWhenHasValidToken(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->hasValidToken(1);

        $this->assertTrue($result);
    }

    public function testHasValidTokenReturnsFalseWhenNoValidToken(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->hasValidToken(1);

        $this->assertFalse($result);
    }

    // countRecentTokens tests

    public function testCountRecentTokensReturnsCount(): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn(3);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->countRecentTokens(1);

        $this->assertEquals(3, $result);
    }

    public function testCountRecentTokensWithCustomMinutes(): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn(1);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->countRecentTokens(1, 30);

        $this->assertEquals(1, $result);
    }

    public function testCountRecentTokensReturnsZeroWhenNoRecentTokens(): void
    {
        $this->stmtMock->method('fetchColumn')->willReturn(0);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->countRecentTokens(999);

        $this->assertEquals(0, $result);
    }
}
