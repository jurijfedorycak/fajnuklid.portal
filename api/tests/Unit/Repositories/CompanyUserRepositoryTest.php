<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CompanyUserRepository;
use InvalidArgumentException;
use PDO;
use Tests\DatabaseTestCase;

class CompanyUserRepositoryTest extends DatabaseTestCase
{
    private CompanyUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(CompanyUserRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsJoinedData(): void
    {
        $expected = [
            'id' => 1,
            'company_id' => 1,
            'user_id' => 1,
            'company_name' => 'Test Company',
            'registration_number' => '12345678',
            'user_email' => 'test@example.com',
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

    // findByCompanyId tests

    public function testFindByCompanyIdReturnsUsers(): void
    {
        $expected = [
            ['id' => 1, 'user_id' => 1, 'user_email' => 'user1@example.com'],
            ['id' => 2, 'user_id' => 2, 'user_email' => 'user2@example.com'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals($expected, $result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsCompanies(): void
    {
        $expected = [
            ['id' => 1, 'company_id' => 1, 'company_name' => 'Company A'],
            ['id' => 2, 'company_id' => 2, 'company_name' => 'Company B'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expected, $result);
    }

    // exists tests

    public function testExistsReturnsTrueWhenRelationExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->exists(1, 1);

        $this->assertTrue($result);
    }

    public function testExistsReturnsFalseWhenRelationNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->exists(999, 999);

        $this->assertFalse($result);
    }

    // create tests

    public function testCreateReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create(1, 1);

        $this->assertEquals(1, $result);
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

    // deleteByCompanyAndUser tests

    public function testDeleteByCompanyAndUserReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByCompanyAndUser(1, 1);

        $this->assertTrue($result);
    }

    public function testDeleteByCompanyAndUserReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteByCompanyAndUser(999, 999);

        $this->assertFalse($result);
    }

    // deleteByCompanyId tests

    public function testDeleteByCompanyIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByCompanyId(1);

        $this->assertEquals(3, $result);
    }

    // deleteByUserId tests

    public function testDeleteByUserIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(5);

        $result = $this->repository->deleteByUserId(1);

        $this->assertEquals(5, $result);
    }

    // syncUserCompanies tests

    public function testSyncUserCompaniesCommitsTransaction(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->syncUserCompanies(1, [1]);
    }

    public function testSyncUserCompaniesRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->syncUserCompanies(1, [1]);
    }

    public function testSyncUserCompaniesValidatesCompanyIds(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid company ID provided');

        $this->repository->syncUserCompanies(1, [-1]);
    }

    // syncCompanyUsers tests

    public function testSyncCompanyUsersCommitsTransaction(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt);

        $this->repository->syncCompanyUsers(1, [1]);
    }

    public function testSyncCompanyUsersValidatesUserIds(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user ID provided');

        $this->repository->syncCompanyUsers(1, [0]);
    }

    // getCompanyIdsByUser tests

    public function testGetCompanyIdsByUserReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['company_id' => 1],
            ['company_id' => 2],
            ['company_id' => 3],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getCompanyIdsByUser(1);

        $this->assertEquals([1, 2, 3], $result);
    }

    // getUserIdsByCompany tests

    public function testGetUserIdsByCompanyReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['user_id' => 1],
            ['user_id' => 2],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getUserIdsByCompany(1);

        $this->assertEquals([1, 2], $result);
    }

    // userHasAccessToCompany tests

    public function testUserHasAccessToCompanyReturnsTrueWhenHasAccess(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->userHasAccessToCompany(1, 1);

        $this->assertTrue($result);
    }

    public function testUserHasAccessToCompanyReturnsFalseWhenNoAccess(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->userHasAccessToCompany(1, 999);

        $this->assertFalse($result);
    }

    // userHasAccessToLocation tests

    public function testUserHasAccessToLocationReturnsTrueWhenHasAccess(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->userHasAccessToLocation(1, 1);

        $this->assertTrue($result);
    }

    public function testUserHasAccessToLocationReturnsFalseWhenNoAccess(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->userHasAccessToLocation(1, 999);

        $this->assertFalse($result);
    }

    // countUserCompanies tests

    public function testCountUserCompaniesReturnsCount(): void
    {
        $this->setupFetchColumnMock(5);

        $result = $this->repository->countUserCompanies(1);

        $this->assertEquals(5, $result);
    }

    // countCompanyUsers tests

    public function testCountCompanyUsersReturnsCount(): void
    {
        $this->setupFetchColumnMock(3);

        $result = $this->repository->countCompanyUsers(1);

        $this->assertEquals(3, $result);
    }
}
