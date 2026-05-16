<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CompanyRoundingRuleRepository;
use Tests\DatabaseTestCase;

class CompanyRoundingRuleRepositoryTest extends DatabaseTestCase
{
    private CompanyRoundingRuleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(CompanyRoundingRuleRepository::class);
    }

    // findByCompanyId

    public function testFindByCompanyIdReturnsCastRows(): void
    {
        $this->setupFetchAllMock([
            [
                'id' => '5',
                'company_id' => '11',
                'threshold_minutes' => '0',
                'interval_minutes' => '60',
                'direction' => 'up',
            ],
            [
                'id' => '6',
                'company_id' => '11',
                'threshold_minutes' => '60',
                'interval_minutes' => '60',
                'direction' => 'down',
            ],
        ]);

        $result = $this->repository->findByCompanyId(11);

        $this->assertCount(2, $result);
        $this->assertSame(5, $result[0]['id']);
        $this->assertSame(11, $result[0]['company_id']);
        $this->assertSame(0, $result[0]['threshold_minutes']);
        $this->assertSame(60, $result[0]['interval_minutes']);
        $this->assertSame('up', $result[0]['direction']);
        $this->assertSame('down', $result[1]['direction']);
    }

    public function testFindByCompanyIdReturnsEmptyArrayWhenNone(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByCompanyId(42);

        $this->assertSame([], $result);
    }

    // findByCompanyIds

    public function testFindByCompanyIdsReturnsEmptyArrayForEmptyInput(): void
    {
        // No prepare/execute should occur — fetchAll mock won't be hit. The
        // repository must short-circuit before touching PDO.
        $result = $this->repository->findByCompanyIds([]);

        $this->assertSame([], $result);
    }

    public function testFindByCompanyIdsBucketsRowsByCompany(): void
    {
        $this->setupFetchAllMock([
            ['id' => '1', 'company_id' => '11', 'threshold_minutes' => '0', 'interval_minutes' => '60', 'direction' => 'up'],
            ['id' => '2', 'company_id' => '11', 'threshold_minutes' => '60', 'interval_minutes' => '0', 'direction' => 'none'],
            ['id' => '3', 'company_id' => '22', 'threshold_minutes' => '0', 'interval_minutes' => '15', 'direction' => 'nearest'],
        ]);

        $result = $this->repository->findByCompanyIds([11, 22, 33]);

        $this->assertArrayHasKey(11, $result);
        $this->assertArrayHasKey(22, $result);
        $this->assertArrayNotHasKey(33, $result);
        $this->assertCount(2, $result[11]);
        $this->assertCount(1, $result[22]);
        $this->assertSame('nearest', $result[22][0]['direction']);
    }

    public function testFindByCompanyIdsDropsInvalidIds(): void
    {
        // Non-positive ids would otherwise embed empty placeholders into the IN
        // clause. The repository filters them; with no valid ids left it must
        // skip the query entirely.
        $result = $this->repository->findByCompanyIds([0, -1]);

        $this->assertSame([], $result);
    }

    // replaceForCompany

    public function testReplaceForCompanyDeletesEvenWhenRulesEmpty(): void
    {
        $this->pdoMock->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->expects($this->once())
            ->method('execute')
            ->with(['company_id' => 7])
            ->willReturn(true);

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->willReturn($deleteStmt);

        $this->repository->replaceForCompany(7, []);
    }

    public function testReplaceForCompanyInsertsRulesInThresholdOrder(): void
    {
        $this->pdoMock->method('inTransaction')->willReturn(false);
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);

        $insertStmt = $this->createStatementMock();
        $insertedThresholds = [];
        $insertedDirections = [];
        $insertedIntervals = [];
        $insertStmt
            ->method('execute')
            ->willReturnCallback(function (array $params) use (
                &$insertedThresholds,
                &$insertedDirections,
                &$insertedIntervals
            ): bool {
                $insertedThresholds[] = $params['threshold_minutes'];
                $insertedDirections[] = $params['direction'];
                $insertedIntervals[] = $params['interval_minutes'];
                return true;
            });

        $this->pdoMock
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        // Pass rules out of order on purpose — the repo must sort before insert.
        $this->repository->replaceForCompany(7, [
            ['threshold_minutes' => 70, 'interval_minutes' => 0, 'direction' => 'none'],
            ['threshold_minutes' => 0, 'interval_minutes' => 60, 'direction' => 'up'],
            ['threshold_minutes' => 60, 'interval_minutes' => 60, 'direction' => 'down'],
        ]);

        $this->assertSame([0, 60, 70], $insertedThresholds);
        $this->assertSame(['up', 'down', 'none'], $insertedDirections);
        $this->assertSame([60, 60, 0], $insertedIntervals);
    }

    public function testReplaceForCompanySkipsNonArrayRuleEntries(): void
    {
        // Even though caller validation should reject these, the repo guards itself:
        // a stray "string" entry must not trigger a PHP 8 "array offset on string" warning.
        $this->pdoMock->method('inTransaction')->willReturn(false);
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);

        $insertStmt = $this->createStatementMock();
        $insertedThresholds = [];
        $insertStmt->method('execute')->willReturnCallback(function (array $params) use (&$insertedThresholds): bool {
            $insertedThresholds[] = $params['threshold_minutes'];
            return true;
        });

        $this->pdoMock->method('prepare')->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->replaceForCompany(7, [
            'garbage string',
            null,
            ['threshold_minutes' => 0, 'interval_minutes' => 60, 'direction' => 'up'],
        ]);

        $this->assertSame([0], $insertedThresholds);
    }

    public function testReplaceForCompanyReusesOuterTransactionWhenOneIsActive(): void
    {
        // Production callers (AdminController::updateClient / createClient) always have
        // an outer transaction running. The repo must not call its own
        // beginTransaction/commit/rollBack in that case — those would either error
        // (nested begin not supported) or fragment the outer atomic save.
        $this->pdoMock->method('inTransaction')->willReturn(true);
        $this->pdoMock->expects($this->never())->method('beginTransaction');
        $this->pdoMock->expects($this->never())->method('commit');
        $this->pdoMock->expects($this->never())->method('rollBack');

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->replaceForCompany(7, [
            ['threshold_minutes' => 0, 'interval_minutes' => 30, 'direction' => 'up'],
        ]);
    }

    public function testReplaceForCompanyLetsExceptionBubbleWhenOuterTransactionOwnsRollback(): void
    {
        // Same outer-transaction case, but the delete throws. The repo must NOT
        // rollBack — the outer transaction owns that decision — yet the exception
        // must still propagate so the outer can react.
        $this->pdoMock->method('inTransaction')->willReturn(true);
        $this->pdoMock->expects($this->never())->method('rollBack');
        $this->pdoMock->expects($this->never())->method('beginTransaction');

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willThrowException(new \PDOException('boom'));
        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(\PDOException::class);
        $this->repository->replaceForCompany(7, []);
    }

    public function testReplaceForCompanyNormalisesIntervalZeroToNoneDirection(): void
    {
        // FE may send interval=0 but a stale direction. The repo collapses these
        // to ('none', 0) so the row is internally consistent — direction='up'
        // with interval=0 has no defined meaning and would confuse the service.
        $this->pdoMock->method('inTransaction')->willReturn(false);
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);

        $captured = null;
        $insertStmt = $this->createStatementMock();
        $insertStmt
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$captured): bool {
                $captured = $params;
                return true;
            });

        $this->pdoMock
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->replaceForCompany(7, [
            ['threshold_minutes' => 0, 'interval_minutes' => 0, 'direction' => 'up'],
        ]);

        $this->assertSame('none', $captured['direction']);
        $this->assertSame(0, $captured['interval_minutes']);
    }

    public function testReplaceForCompanyRollsBackOnFailure(): void
    {
        $this->pdoMock->method('inTransaction')->willReturn(false, true);
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->expects($this->never())->method('commit');

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willThrowException(new \PDOException('DB down'));

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(\PDOException::class);
        $this->repository->replaceForCompany(7, []);
    }

    // deleteByCompanyId

    public function testDeleteByCompanyIdReturnsCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByCompanyId(7);

        $this->assertSame(3, $result);
    }
}
