<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\IDokladTokenRepository;
use DateTime;
use PDO;
use Tests\DatabaseTestCase;

class IDokladTokenRepositoryTest extends DatabaseTestCase
{
    private IDokladTokenRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(IDokladTokenRepository::class);
    }

    // getLatestToken tests

    public function testGetLatestTokenReturnsTokenWhenExists(): void
    {
        $expected = [
            'id' => 1,
            'access_token' => 'token123',
            'expires_at' => '2025-01-15 12:00:00',
            'created_at' => '2024-01-01 12:00:00',
        ];

        $this->stmtMock->method('fetch')->willReturn($expected);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getLatestToken();

        $this->assertEquals($expected, $result);
    }

    public function testGetLatestTokenReturnsNullWhenNoTokens(): void
    {
        $this->stmtMock->method('fetch')->willReturn(false);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getLatestToken();

        $this->assertNull($result);
    }

    // isTokenValid tests

    public function testIsTokenValidReturnsTrueWhenNotExpired(): void
    {
        $futureDate = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $token = [
            'id' => 1,
            'access_token' => 'token123',
            'expires_at' => $futureDate,
        ];

        $this->stmtMock->method('fetch')->willReturn($token);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->isTokenValid();

        $this->assertTrue($result);
    }

    public function testIsTokenValidReturnsFalseWhenExpired(): void
    {
        $pastDate = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $token = [
            'id' => 1,
            'access_token' => 'token123',
            'expires_at' => $pastDate,
        ];

        $this->stmtMock->method('fetch')->willReturn($token);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->isTokenValid();

        $this->assertFalse($result);
    }

    public function testIsTokenValidReturnsFalseWhenExpiresWithin60Seconds(): void
    {
        $nearFutureDate = (new DateTime())->modify('+30 seconds')->format('Y-m-d H:i:s');
        $token = [
            'id' => 1,
            'access_token' => 'token123',
            'expires_at' => $nearFutureDate,
        ];

        $this->stmtMock->method('fetch')->willReturn($token);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->isTokenValid();

        $this->assertFalse($result);
    }

    public function testIsTokenValidReturnsFalseWhenNoToken(): void
    {
        $this->stmtMock->method('fetch')->willReturn(false);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->isTokenValid();

        $this->assertFalse($result);
    }

    // getValidToken tests

    public function testGetValidTokenReturnsTokenWhenValid(): void
    {
        $futureDate = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $token = [
            'id' => 1,
            'access_token' => 'validtoken123',
            'expires_at' => $futureDate,
        ];

        $this->stmtMock->method('fetch')->willReturn($token);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getValidToken();

        $this->assertEquals('validtoken123', $result);
    }

    public function testGetValidTokenReturnsNullWhenInvalid(): void
    {
        $pastDate = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $token = [
            'id' => 1,
            'access_token' => 'expiredtoken',
            'expires_at' => $pastDate,
        ];

        $this->stmtMock->method('fetch')->willReturn($token);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getValidToken();

        $this->assertNull($result);
    }

    public function testGetValidTokenReturnsNullWhenNoToken(): void
    {
        $this->stmtMock->method('fetch')->willReturn(false);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->getValidToken();

        $this->assertNull($result);
    }

    // saveToken tests

    public function testSaveTokenReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $expiresAt = new DateTime('+1 hour');
        $result = $this->repository->saveToken('newtoken', $expiresAt);

        $this->assertEquals(1, $result);
    }

    public function testSaveTokenFormatsDateCorrectly(): void
    {
        $expiresAt = new DateTime('2024-06-15 14:30:00');

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return $params['expires_at'] === '2024-06-15 14:30:00';
            }))
            ->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->saveToken('token', $expiresAt);
    }

    // deleteExpiredTokens tests

    public function testDeleteExpiredTokensReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteExpiredTokens();

        $this->assertEquals(3, $result);
    }

    public function testDeleteExpiredTokensReturnsZeroWhenNoneExpired(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteExpiredTokens();

        $this->assertEquals(0, $result);
    }

    // deleteAllTokens tests

    public function testDeleteAllTokensReturnsDeletedCount(): void
    {
        $this->stmtMock->method('rowCount')->willReturn(5);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->deleteAllTokens();

        $this->assertEquals(5, $result);
    }

    public function testDeleteAllTokensReturnsZeroWhenNoTokens(): void
    {
        $this->stmtMock->method('rowCount')->willReturn(0);
        $this->pdoMock->method('query')->willReturn($this->stmtMock);

        $result = $this->repository->deleteAllTokens();

        $this->assertEquals(0, $result);
    }
}
