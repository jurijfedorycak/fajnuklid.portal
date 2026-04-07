<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CompanyRepository;
use PDO;
use Tests\DatabaseTestCase;

class CompanyRepositoryTest extends DatabaseTestCase
{
    private CompanyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(CompanyRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsCompanyWithClientJoin(): void
    {
        $expectedCompany = [
            'id' => 1,
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'Test Company',
            'address' => '123 Test St',
            'contract_start_date' => '2024-01-01',
            'contract_end_date' => null,
            'contract_pdf_path' => null,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
            'client_name' => 'Test Client',
        ];

        $this->setupFetchMock($expectedCompany);

        $result = $this->repository->findById(1);

        $this->assertEquals($expectedCompany, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findById(999);

        $this->assertNull($result);
    }

    // findByRegistrationNumber tests

    public function testFindByRegistrationNumberReturnsCompanyWhenFound(): void
    {
        $expectedCompany = [
            'id' => 1,
            'registration_number' => '12345678',
            'name' => 'Test Company',
        ];

        $this->setupFetchMock($expectedCompany);

        $result = $this->repository->findByRegistrationNumber('12345678');

        $this->assertEquals($expectedCompany, $result);
    }

    public function testFindByRegistrationNumberReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByRegistrationNumber('00000000');

        $this->assertNull($result);
    }

    // findByClientId tests

    public function testFindByClientIdReturnsCompanies(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Company A', 'client_id' => 1],
            ['id' => 2, 'name' => 'Company B', 'client_id' => 1],
        ];

        $this->setupFetchAllMock($expectedCompanies);

        $result = $this->repository->findByClientId(1);

        $this->assertEquals($expectedCompanies, $result);
    }

    public function testFindByClientIdReturnsEmptyArrayWhenNoCompanies(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByClientId(999);

        $this->assertEquals([], $result);
    }

    // findAll tests

    public function testFindAllReturnsAllCompaniesWithClientNames(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Company A', 'client_name' => 'Client 1'],
            ['id' => 2, 'name' => 'Company B', 'client_name' => 'Client 2'],
        ];

        $this->setupQueryMock($expectedCompanies);

        $result = $this->repository->findAll();

        $this->assertEquals($expectedCompanies, $result);
    }

    // findPaginated tests

    public function testFindPaginatedWithoutFilters(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Company A'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedCompanies);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0);

        $this->assertEquals($expectedCompanies, $result);
    }

    public function testFindPaginatedWithSearchByName(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Test Company'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedCompanies);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'Test');

        $this->assertEquals($expectedCompanies, $result);
    }

    public function testFindPaginatedWithClientFilter(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Company A', 'client_id' => 5],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedCompanies);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, null, 5);

        $this->assertEquals($expectedCompanies, $result);
    }

    public function testFindPaginatedWithCombinedFilters(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Test Company', 'client_id' => 5],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expectedCompanies);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->stmtMock->method('bindValue')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findPaginated(10, 0, 'Test', 5);

        $this->assertEquals($expectedCompanies, $result);
    }

    // countAll tests

    public function testCountAllWithoutFilters(): void
    {
        $this->setupFetchColumnMock(10);

        $result = $this->repository->countAll();

        $this->assertEquals(10, $result);
    }

    public function testCountAllWithSearch(): void
    {
        $this->setupFetchColumnMock(3);

        $result = $this->repository->countAll('test');

        $this->assertEquals(3, $result);
    }

    public function testCountAllWithClientFilter(): void
    {
        $this->setupFetchColumnMock(5);

        $result = $this->repository->countAll(null, 1);

        $this->assertEquals(5, $result);
    }

    // create tests

    public function testCreateReturnsNewCompanyId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithAllFields(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
            'address' => '123 Test St',
            'contract_start_date' => '2024-01-01',
            'contract_end_date' => '2025-01-01',
            'contract_pdf_path' => '/path/to/contract.pdf',
        ]);

        $this->assertEquals(2, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['name' => 'Updated Company']);

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

    public function testUpdateWithPartialData(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, [
            'name' => 'Updated Name',
            'address' => 'New Address',
        ]);

        $this->assertTrue($result);
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

    // existsByRegistrationNumber tests

    public function testExistsByRegistrationNumberReturnsTrueWhenExists(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->existsByRegistrationNumber('12345678');

        $this->assertTrue($result);
    }

    public function testExistsByRegistrationNumberReturnsFalseWhenNotExists(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByRegistrationNumber('00000000');

        $this->assertFalse($result);
    }

    public function testExistsByRegistrationNumberWithExcludeId(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->existsByRegistrationNumber('12345678', 1);

        $this->assertFalse($result);
    }

    // findByUserId tests

    public function testFindByUserIdReturnsCompanies(): void
    {
        $expectedCompanies = [
            ['id' => 1, 'name' => 'Company A'],
            ['id' => 2, 'name' => 'Company B'],
        ];

        $this->setupFetchAllMock($expectedCompanies);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expectedCompanies, $result);
    }

    public function testFindByUserIdReturnsEmptyArrayWhenNoCompanies(): void
    {
        $this->setupFetchAllMock([]);

        $result = $this->repository->findByUserId(999);

        $this->assertEquals([], $result);
    }

    // hasActiveContract tests

    public function testHasActiveContractReturnsTrueWhenActive(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->hasActiveContract(1);

        $this->assertTrue($result);
    }

    public function testHasActiveContractReturnsTrueWhenNullEndDate(): void
    {
        $this->setupFetchColumnMock(1);

        $result = $this->repository->hasActiveContract(1);

        $this->assertTrue($result);
    }

    public function testHasActiveContractReturnsFalseWhenExpired(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->hasActiveContract(1);

        $this->assertFalse($result);
    }

    public function testHasActiveContractReturnsFalseWhenNoContract(): void
    {
        $this->setupFetchColumnMock(0);

        $result = $this->repository->hasActiveContract(1);

        $this->assertFalse($result);
    }
}
