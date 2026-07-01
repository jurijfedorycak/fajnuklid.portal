<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\AppSettingRepository;
use Tests\DatabaseTestCase;

class AppSettingRepositoryTest extends DatabaseTestCase
{
    private AppSettingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(AppSettingRepository::class);
    }

    public function testGetReturnsStoredValue(): void
    {
        $this->setupFetchColumnMock('https://g.page/r/abc');

        $result = $this->repository->get(AppSettingRepository::KEY_GOOGLE_REVIEW_URL);

        $this->assertSame('https://g.page/r/abc', $result);
    }

    public function testGetReturnsNullWhenRowMissing(): void
    {
        // fetchColumn() yields false when no row matches.
        $this->setupFetchColumnMock(false);

        $result = $this->repository->get('missing_key');

        $this->assertNull($result);
    }

    public function testGetReturnsNullWhenValueIsNull(): void
    {
        $this->setupFetchColumnMock(null);

        $result = $this->repository->get(AppSettingRepository::KEY_GOOGLE_REVIEW_URL);

        $this->assertNull($result);
    }

    public function testSetUpsertsKeyAndValue(): void
    {
        $captured = null;
        $sqlSeen = '';
        $this->pdoMock->method('prepare')
            ->willReturnCallback(function ($sql) use (&$sqlSeen) {
                $sqlSeen = $sql;
                return $this->stmtMock;
            });
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });

        $this->repository->set(AppSettingRepository::KEY_GOOGLE_REVIEW_URL, 'https://g.page/r/xyz');

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sqlSeen);
        $this->assertSame('google_review_url', $captured['key']);
        $this->assertSame('https://g.page/r/xyz', $captured['value']);
    }

    public function testSetStoresNullValue(): void
    {
        $captured = null;
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->stmtMock->method('execute')
            ->willReturnCallback(function ($params) use (&$captured) {
                $captured = $params;
                return true;
            });

        $this->repository->set(AppSettingRepository::KEY_GOOGLE_REVIEW_URL, null);

        $this->assertNull($captured['value']);
    }
}
