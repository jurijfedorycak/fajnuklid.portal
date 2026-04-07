<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CompanyContactRepository;
use InvalidArgumentException;
use PDO;
use Tests\DatabaseTestCase;

class CompanyContactRepositoryTest extends DatabaseTestCase
{
    private CompanyContactRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(CompanyContactRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsJoinedData(): void
    {
        $expected = [
            'id' => 1,
            'company_id' => 1,
            'contact_id' => 1,
            'is_primary' => 1,
            'company_name' => 'Test Company',
            'contact_name' => 'John Doe',
            'contact_position' => 'Manager',
            'contact_phone' => '+420123456789',
            'contact_email' => 'john@example.com',
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

    public function testFindByCompanyIdReturnsContactsOrderedByPrimary(): void
    {
        $expected = [
            ['id' => 2, 'contact_name' => 'Primary', 'is_primary' => 1],
            ['id' => 1, 'contact_name' => 'Secondary', 'is_primary' => 0],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByCompanyId(1);

        $this->assertEquals(1, $result[0]['is_primary']);
    }

    // findByContactId tests

    public function testFindByContactIdReturnsCompanies(): void
    {
        $expected = [
            ['id' => 1, 'company_id' => 1, 'company_name' => 'Company A'],
            ['id' => 2, 'company_id' => 2, 'company_name' => 'Company B'],
        ];

        $this->setupFetchAllMock($expected);

        $result = $this->repository->findByContactId(1);

        $this->assertEquals($expected, $result);
    }

    // findPrimaryContact tests

    public function testFindPrimaryContactReturnsContact(): void
    {
        $expected = [
            'id' => 1,
            'contact_name' => 'Primary Contact',
            'is_primary' => 1,
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findPrimaryContact(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindPrimaryContactReturnsNullWhenNone(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findPrimaryContact(1);

        $this->assertNull($result);
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
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')->willReturn($insertStmt);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $result = $this->repository->create(1, 1, false);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithPrimaryFlagClearsExisting(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $clearStmt = $this->createStatementMock();
        $clearStmt->method('execute')->willReturn(true);
        $clearStmt->method('rowCount')->willReturn(1);

        $insertStmt = $this->createStatementMock();
        $insertStmt->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($clearStmt, $insertStmt);
        $this->pdoMock->method('lastInsertId')->willReturn('2');

        $result = $this->repository->create(1, 2, true);

        $this->assertEquals(2, $result);
    }

    public function testCreateRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->create(1, 1, false);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $updateStmt = $this->createStatementMock();
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(1);

        $this->pdoMock->method('prepare')->willReturn($updateStmt);

        $result = $this->repository->update(1, ['is_primary' => false]);

        $this->assertTrue($result);
    }

    public function testUpdateWithPrimaryFlagClearsExisting(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $findStmt = $this->createStatementMock();
        $findStmt->method('fetch')->willReturn(['id' => 1, 'company_id' => 1]);
        $findStmt->method('execute')->willReturn(true);

        $clearStmt = $this->createStatementMock();
        $clearStmt->method('execute')->willReturn(true);
        $clearStmt->method('rowCount')->willReturn(1);

        $updateStmt = $this->createStatementMock();
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(1);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($findStmt, $clearStmt, $updateStmt);

        $result = $this->repository->update(1, ['is_primary' => true]);

        $this->assertTrue($result);
    }

    // setPrimary tests

    public function testSetPrimaryReturnsTrueWhenSet(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('commit')->willReturn(true);

        $clearStmt = $this->createStatementMock();
        $clearStmt->method('execute')->willReturn(true);
        $clearStmt->method('rowCount')->willReturn(1);

        $updateStmt = $this->createStatementMock();
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(1);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($clearStmt, $updateStmt);

        $result = $this->repository->setPrimary(1, 1);

        $this->assertTrue($result);
    }

    public function testSetPrimaryRollsBackOnFailure(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdoMock->method('prepare')->willThrowException(new \Exception('DB Error'));

        $this->expectException(\Exception::class);

        $this->repository->setPrimary(1, 1);
    }

    // clearPrimary tests

    public function testClearPrimaryReturnsUpdatedCount(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->clearPrimary(1);

        $this->assertEquals(1, $result);
    }

    public function testClearPrimaryReturnsZeroWhenNoPrimary(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->clearPrimary(1);

        $this->assertEquals(0, $result);
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

    // deleteByCompanyAndContact tests

    public function testDeleteByCompanyAndContactReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByCompanyAndContact(1, 1);

        $this->assertTrue($result);
    }

    // deleteByCompanyId tests

    public function testDeleteByCompanyIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(3);

        $result = $this->repository->deleteByCompanyId(1);

        $this->assertEquals(3, $result);
    }

    // deleteByContactId tests

    public function testDeleteByContactIdReturnsDeletedCount(): void
    {
        $this->setupRowCountMock(2);

        $result = $this->repository->deleteByContactId(1);

        $this->assertEquals(2, $result);
    }

    // syncCompanyContacts tests

    public function testSyncCompanyContactsCommitsTransaction(): void
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

        $this->repository->syncCompanyContacts(1, [1]);
    }

    public function testSyncCompanyContactsValidatesContactIds(): void
    {
        $this->pdoMock->method('beginTransaction')->willReturn(true);
        $this->pdoMock->method('rollBack')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $this->pdoMock->method('prepare')->willReturn($deleteStmt);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contact ID provided');

        $this->repository->syncCompanyContacts(1, [-1]);
    }

    public function testSyncCompanyContactsWithPrimaryContact(): void
    {
        $this->pdoMock->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdoMock->expects($this->once())->method('commit')->willReturn(true);

        $deleteStmt = $this->createStatementMock();
        $deleteStmt->method('execute')->willReturn(true);
        $deleteStmt->method('rowCount')->willReturn(0);

        $insertStmt = $this->createStatementMock();
        $insertStmt->expects($this->exactly(2))->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($deleteStmt, $insertStmt, $insertStmt);

        $this->repository->syncCompanyContacts(1, [1, 2], 2);
    }

    // getContactIdsByCompany tests

    public function testGetContactIdsByCompanyReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['contact_id' => 1],
            ['contact_id' => 2],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getContactIdsByCompany(1);

        $this->assertEquals([1, 2], $result);
    }

    // getCompanyIdsByContact tests

    public function testGetCompanyIdsByContactReturnsIds(): void
    {
        $this->stmtMock->method('fetchAll')->willReturn([
            ['company_id' => 1],
            ['company_id' => 2],
            ['company_id' => 3],
        ]);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->getCompanyIdsByContact(1);

        $this->assertEquals([1, 2, 3], $result);
    }
}
