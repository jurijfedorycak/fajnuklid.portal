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

    // normaliseFreshqrMode tests

    public function testNormaliseFreshqrModeAcceptsValidLowercaseValues(): void
    {
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode('off'));
        $this->assertSame('basic', CompanyRepository::normaliseFreshqrMode('basic'));
        $this->assertSame('detailed', CompanyRepository::normaliseFreshqrMode('detailed'));
    }

    public function testNormaliseFreshqrModeLowercasesAndTrimsInput(): void
    {
        $this->assertSame('basic', CompanyRepository::normaliseFreshqrMode('BASIC'));
        $this->assertSame('detailed', CompanyRepository::normaliseFreshqrMode('  Detailed '));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode('OFF'));
    }

    public function testNormaliseFreshqrModeFallsBackToOffForUnknownStrings(): void
    {
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode('gibberish'));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode(''));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode('basic_extra'));
    }

    public function testNormaliseFreshqrModeFallsBackToOffForNonStringInput(): void
    {
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode(null));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode(1));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode(['detailed']));
        $this->assertSame('off', CompanyRepository::normaliseFreshqrMode(true));
    }

    // freshqr_mode round-trip through update()

    public function testUpdateNormalisesFreshqrModeBeforeBindingValue(): void
    {
        // Capturing the bound parameter is the cleanest way to assert the
        // normaliser ran on the way in (without standing up a real DB).
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['freshqr_mode' => '  DETAILED ']);

        $this->assertSame('detailed', $captured['freshqr_mode'] ?? null);
    }

    public function testUpdateRejectsUnknownFreshqrModeByCoercingToOff(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['freshqr_mode' => 'evil-injected-value']);

        $this->assertSame('off', $captured['freshqr_mode'] ?? null);
    }

    // normaliseBillingModel tests

    public function testNormaliseBillingModelAcceptsValidLowercaseValues(): void
    {
        $this->assertSame('hourly', CompanyRepository::normaliseBillingModel('hourly'));
        $this->assertSame('fixed', CompanyRepository::normaliseBillingModel('fixed'));
    }

    public function testNormaliseBillingModelLowercasesAndTrimsInput(): void
    {
        $this->assertSame('hourly', CompanyRepository::normaliseBillingModel('HOURLY'));
        $this->assertSame('fixed', CompanyRepository::normaliseBillingModel('  Fixed '));
    }

    public function testNormaliseBillingModelReturnsNullForUnknownStrings(): void
    {
        $this->assertNull(CompanyRepository::normaliseBillingModel('gibberish'));
        $this->assertNull(CompanyRepository::normaliseBillingModel(''));
        $this->assertNull(CompanyRepository::normaliseBillingModel('hourly_extra'));
    }

    public function testNormaliseBillingModelReturnsNullForNonStringInput(): void
    {
        $this->assertNull(CompanyRepository::normaliseBillingModel(null));
        $this->assertNull(CompanyRepository::normaliseBillingModel(1));
        $this->assertNull(CompanyRepository::normaliseBillingModel(['hourly']));
        $this->assertNull(CompanyRepository::normaliseBillingModel(true));
    }

    // billing_model round-trip through update()

    public function testUpdateNormalisesBillingModelBeforeBindingValue(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => '  FIXED ']);

        $this->assertSame('fixed', $captured['billing_model'] ?? 'missing');
    }

    public function testUpdatePersistsNullBillingModelForNeurceno(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => null]);

        // null must round-trip as null so "Neurčeno" is preserved end-to-end.
        $this->assertArrayHasKey('billing_model', $captured);
        $this->assertNull($captured['billing_model']);
    }

    public function testUpdateCoercesUnknownBillingModelToNull(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => 'evil-injected-value']);

        $this->assertArrayHasKey('billing_model', $captured);
        $this->assertNull($captured['billing_model']);
    }

    public function testCreateNormalisesBillingModelBeforeBindingValue(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
            'billing_model' => '  HOURLY ',
        ]);

        $this->assertSame('hourly', $captured['billing_model'] ?? 'missing');
    }

    public function testCreatePersistsNullBillingModelWhenOmitted(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        // Caller didn't pass billing_model at all — must default to null so the
        // FE "Neurčeno" state survives the create path for new IČOs.
        $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
        ]);

        $this->assertArrayHasKey('billing_model', $captured);
        $this->assertNull($captured['billing_model']);
    }

    // normaliseHourlyRate tests

    public function testNormaliseHourlyRateAcceptsPositiveInteger(): void
    {
        $this->assertSame('250.00', CompanyRepository::normaliseHourlyRate(250));
    }

    public function testNormaliseHourlyRateAcceptsPositiveFloat(): void
    {
        $this->assertSame('250.50', CompanyRepository::normaliseHourlyRate(250.5));
        $this->assertSame('99.99', CompanyRepository::normaliseHourlyRate(99.99));
    }

    public function testNormaliseHourlyRateAcceptsNumericString(): void
    {
        $this->assertSame('250.00', CompanyRepository::normaliseHourlyRate('250'));
        $this->assertSame('250.50', CompanyRepository::normaliseHourlyRate('250.50'));
        $this->assertSame('250.50', CompanyRepository::normaliseHourlyRate(' 250.50 '));
    }

    public function testNormaliseHourlyRateRejectsCommaDecimalSeparator(): void
    {
        // FE uses <input type="number">, which strips commas before submit.
        // Mirroring that strictness here keeps FE pre-validation and BE in lockstep.
        $this->assertNull(CompanyRepository::normaliseHourlyRate('250,50'));
    }

    public function testNormaliseHourlyRateAcceptsZero(): void
    {
        $this->assertSame('0.00', CompanyRepository::normaliseHourlyRate(0));
        $this->assertSame('0.00', CompanyRepository::normaliseHourlyRate('0'));
    }

    public function testNormaliseHourlyRateReturnsNullForNullAndEmpty(): void
    {
        $this->assertNull(CompanyRepository::normaliseHourlyRate(null));
        $this->assertNull(CompanyRepository::normaliseHourlyRate(''));
        $this->assertNull(CompanyRepository::normaliseHourlyRate('   '));
    }

    public function testNormaliseHourlyRateReturnsNullForNegative(): void
    {
        $this->assertNull(CompanyRepository::normaliseHourlyRate(-1));
        $this->assertNull(CompanyRepository::normaliseHourlyRate('-250.50'));
    }

    public function testNormaliseHourlyRateReturnsNullForNonNumeric(): void
    {
        $this->assertNull(CompanyRepository::normaliseHourlyRate('abc'));
        $this->assertNull(CompanyRepository::normaliseHourlyRate('250abc'));
        $this->assertNull(CompanyRepository::normaliseHourlyRate('evil-injection'));
    }

    public function testNormaliseHourlyRateReturnsNullForBoolAndArray(): void
    {
        $this->assertNull(CompanyRepository::normaliseHourlyRate(true));
        $this->assertNull(CompanyRepository::normaliseHourlyRate(false));
        $this->assertNull(CompanyRepository::normaliseHourlyRate([250]));
    }

    public function testNormaliseHourlyRateReturnsNullForOverflow(): void
    {
        // DECIMAL(10,2) caps at 99999999.99 — anything beyond would silently
        // overflow on insert, so we reject it up-front.
        $this->assertNull(CompanyRepository::normaliseHourlyRate(100000000));
        $this->assertNull(CompanyRepository::normaliseHourlyRate(999999999.99));
    }

    // hourly_rate round-trip through update()

    public function testUpdateNormalisesHourlyRateBeforeBindingValue(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => 'hourly', 'hourly_rate' => 250.5]);

        $this->assertSame('250.50', $captured['hourly_rate'] ?? 'missing');
    }

    public function testUpdateForcesHourlyRateToNullWhenBillingModelIsFixed(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        // Defense in depth: even if a stale FE payload sends both
        // billing_model=fixed AND hourly_rate=250, the rate must be wiped.
        $this->repository->update(7, ['billing_model' => 'fixed', 'hourly_rate' => 250]);

        $this->assertArrayHasKey('hourly_rate', $captured);
        $this->assertNull($captured['hourly_rate']);
    }

    public function testUpdateForcesHourlyRateToNullWhenBillingModelIsNull(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => null, 'hourly_rate' => 250]);

        $this->assertArrayHasKey('hourly_rate', $captured);
        $this->assertNull($captured['hourly_rate']);
    }

    public function testUpdateCoercesNegativeHourlyRateToNull(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['billing_model' => 'hourly', 'hourly_rate' => -50]);

        $this->assertArrayHasKey('hourly_rate', $captured);
        $this->assertNull($captured['hourly_rate']);
    }

    public function testCreatePersistsHourlyRateWhenBillingModelIsHourly(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
            'billing_model' => 'hourly',
            'hourly_rate' => 250.5,
        ]);

        $this->assertSame('250.50', $captured['hourly_rate'] ?? 'missing');
    }

    public function testCreateForcesHourlyRateToNullWhenBillingModelIsNotHourly(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
            'billing_model' => 'fixed',
            'hourly_rate' => 250,
        ]);

        $this->assertArrayHasKey('hourly_rate', $captured);
        $this->assertNull($captured['hourly_rate']);
    }

    public function testCreatePersistsNullHourlyRateWhenOmitted(): void
    {
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->repository->create([
            'client_id' => 1,
            'registration_number' => '12345678',
            'name' => 'New Company',
        ]);

        $this->assertArrayHasKey('hourly_rate', $captured);
        $this->assertNull($captured['hourly_rate']);
    }

    public function testUpdatePartialPayloadWithOnlyHourlyRateTrustsExistingBillingModel(): void
    {
        // When the caller omits billing_model from the partial update, we cannot
        // know the row's current state, so the force-null invariant cannot fire
        // here. The rate is written as-is. AdminController always sends both
        // fields together, so this edge case is theoretical today — this test
        // locks in the current behaviour so a future refactor catches the drift.
        $captured = null;
        $this->stmtMock->method('execute')->willReturnCallback(function ($params) use (&$captured) {
            $captured = $params;
            return true;
        });
        $this->stmtMock->method('rowCount')->willReturn(1);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $this->repository->update(7, ['hourly_rate' => 250]);

        $this->assertSame('250.00', $captured['hourly_rate'] ?? 'missing');
        $this->assertArrayNotHasKey('billing_model', $captured);
    }
}
