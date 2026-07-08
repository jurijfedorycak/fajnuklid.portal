<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\IDokladTokenRepository;
use DateTime;
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
            'account_key' => 'main',
            'access_token' => 'token123',
            'expires_at' => '2025-01-15 12:00:00',
            'created_at' => '2024-01-01 12:00:00',
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->getLatestToken('main');

        $this->assertEquals($expected, $result);
    }

    public function testGetLatestTokenScopesQueryToAccount(): void
    {
        $capturedParams = null;
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
        $this->stmtMock->method('fetch')->willReturn(false);

        $this->repository->getLatestToken('optim1');

        $this->assertSame('optim1', $capturedParams['account_key'] ?? null);
    }

    public function testGetLatestTokenReturnsNullWhenNoTokens(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->getLatestToken('main');

        $this->assertNull($result);
    }

    // isTokenValid tests

    public function testIsTokenValidReturnsTrueWhenNotExpired(): void
    {
        $futureDate = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $this->setupFetchMock([
            'id' => 1,
            'account_key' => 'main',
            'access_token' => 'token123',
            'expires_at' => $futureDate,
        ]);

        $this->assertTrue($this->repository->isTokenValid('main'));
    }

    public function testIsTokenValidReturnsFalseWhenExpired(): void
    {
        $pastDate = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->setupFetchMock([
            'id' => 1,
            'account_key' => 'main',
            'access_token' => 'token123',
            'expires_at' => $pastDate,
        ]);

        $this->assertFalse($this->repository->isTokenValid('main'));
    }

    public function testIsTokenValidReturnsFalseWhenExpiresWithin60Seconds(): void
    {
        $nearFutureDate = (new DateTime())->modify('+30 seconds')->format('Y-m-d H:i:s');
        $this->setupFetchMock([
            'id' => 1,
            'account_key' => 'main',
            'access_token' => 'token123',
            'expires_at' => $nearFutureDate,
        ]);

        $this->assertFalse($this->repository->isTokenValid('main'));
    }

    public function testIsTokenValidReturnsFalseWhenNoToken(): void
    {
        $this->setupFetchMock(false);

        $this->assertFalse($this->repository->isTokenValid('main'));
    }

    // getValidToken tests

    public function testGetValidTokenReturnsTokenWhenValid(): void
    {
        $futureDate = (new DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');
        $this->setupFetchMock([
            'id' => 1,
            'account_key' => 'main',
            'access_token' => 'validtoken123',
            'expires_at' => $futureDate,
        ]);

        $this->assertEquals('validtoken123', $this->repository->getValidToken('main'));
    }

    public function testGetValidTokenReturnsNullWhenInvalid(): void
    {
        $pastDate = (new DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $this->setupFetchMock([
            'id' => 1,
            'account_key' => 'main',
            'access_token' => 'expiredtoken',
            'expires_at' => $pastDate,
        ]);

        $this->assertNull($this->repository->getValidToken('main'));
    }

    public function testGetValidTokenReturnsNullWhenNoToken(): void
    {
        $this->setupFetchMock(false);

        $this->assertNull($this->repository->getValidToken('main'));
    }

    // saveToken tests

    public function testSaveTokenReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->saveToken('main', 'newtoken', new DateTime('+1 hour'));

        $this->assertEquals(1, $result);
    }

    public function testSaveTokenPersistsAccountKeyAndFormatsDate(): void
    {
        $expiresAt = new DateTime('2024-06-15 14:30:00');

        $this->stmtMock->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return $params['account_key'] === 'optim1'
                    && $params['expires_at'] === '2024-06-15 14:30:00';
            }))
            ->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->saveToken('optim1', 'token', $expiresAt);
    }

    // deleteExpiredTokens tests

    public function testDeleteExpiredTokensReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $this->assertEquals(3, $this->repository->deleteExpiredTokens());
    }

    public function testDeleteExpiredTokensReturnsZeroWhenNoneExpired(): void
    {
        $this->setupRowCountMock(0);

        $this->assertEquals(0, $this->repository->deleteExpiredTokens());
    }

    // deleteAllTokens tests

    public function testDeleteAllTokensScopesToAccountAndReturnsCount(): void
    {
        $capturedParams = null;
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('execute')
            ->willReturnCallback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });
        $this->stmtMock->method('rowCount')->willReturn(5);

        $result = $this->repository->deleteAllTokens('main');

        $this->assertEquals(5, $result);
        $this->assertSame('main', $capturedParams['account_key'] ?? null);
    }

    public function testDeleteAllTokensReturnsZeroWhenNoTokens(): void
    {
        $this->setupRowCountMock(0);

        $this->assertEquals(0, $this->repository->deleteAllTokens('main'));
    }
}
