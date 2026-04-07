<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Helpers\IDokladClient;
use App\Repositories\CompanyRepository;
use App\Repositories\InvoiceRepository;
use App\Services\IDokladService;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Tests\TestCase;

class IDokladServiceTest extends TestCase
{
    private IDokladService $service;
    private MockObject&IDokladClient $clientMock;
    private MockObject&InvoiceRepository $invoiceRepoMock;
    private MockObject&CompanyRepository $companyRepoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = $this->createMock(IDokladClient::class);
        $this->invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $this->companyRepoMock = $this->createMock(CompanyRepository::class);

        $reflection = new ReflectionClass(IDokladService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $clientProp = $reflection->getProperty('client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($this->service, $this->clientMock);

        $invoiceRepoProp = $reflection->getProperty('invoiceRepo');
        $invoiceRepoProp->setAccessible(true);
        $invoiceRepoProp->setValue($this->service, $this->invoiceRepoMock);

        $companyRepoProp = $reflection->getProperty('companyRepo');
        $companyRepoProp->setAccessible(true);
        $companyRepoProp->setValue($this->service, $this->companyRepoMock);
    }

    // isConfigured tests

    public function testIsConfiguredReturnsTrueWhenClientConfigured(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(true);

        $this->assertTrue($this->service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenClientNotConfigured(): void
    {
        $this->clientMock->method('isConfigured')->willReturn(false);

        $this->assertFalse($this->service->isConfigured());
    }

    // syncInvoicesForCompany tests

    public function testSyncInvoicesForCompanyReturnsErrorWhenCompanyNotFound(): void
    {
        $this->companyRepoMock->method('findById')->willReturn(null);

        $result = $this->service->syncInvoicesForCompany(999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Firma nebyla nalezena', $result['message']);
        $this->assertEquals(0, $result['synced']);
    }

    public function testSyncInvoicesForCompanyReturnsErrorWhenNoIco(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 1,
            'name' => 'Test Company',
            'registration_number' => '',
        ]);

        $result = $this->service->syncInvoicesForCompany(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Firma nemá IČO', $result['message']);
        $this->assertEquals(0, $result['synced']);
    }

    public function testSyncInvoicesForCompanyReturnsErrorWhenNotConfigured(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 1,
            'name' => 'Test Company',
            'registration_number' => '12345678',
        ]);
        $this->clientMock->method('isConfigured')->willReturn(false);

        $result = $this->service->syncInvoicesForCompany(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('iDoklad není nakonfigurován', $result['message']);
        $this->assertEquals(0, $result['synced']);
    }

    public function testSyncInvoicesForCompanyReturnsSuccessWhenEmptyList(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 1,
            'registration_number' => '12345678',
        ]);
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getAllInvoicesByIco')->willReturn([]);

        $result = $this->service->syncInvoicesForCompany(1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Žádné faktury k synchronizaci', $result['message']);
        $this->assertEquals(0, $result['synced']);
    }

    public function testSyncInvoicesForCompanySyncsInvoicesSuccessfully(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 1,
            'registration_number' => '12345678',
        ]);
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getAllInvoicesByIco')->willReturn([
            ['Id' => 1, 'DocumentNumber' => 'INV-001', 'IsPaid' => false, 'DateOfMaturity' => '2025-01-01'],
            ['Id' => 2, 'DocumentNumber' => 'INV-002', 'IsPaid' => true, 'DateOfMaturity' => '2024-01-01'],
        ]);
        $this->invoiceRepoMock->expects($this->exactly(2))
            ->method('upsertFromIdoklad');

        $result = $this->service->syncInvoicesForCompany(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['synced']);
    }

    // syncInvoicesForUser tests

    public function testSyncInvoicesForUserSyncsMultipleCompanies(): void
    {
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['id' => 1, 'name' => 'Company A', 'registration_number' => '12345678'],
            ['id' => 2, 'name' => 'Company B', 'registration_number' => '87654321'],
        ]);
        $this->companyRepoMock->method('findById')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'registration_number' => '12345678'],
                ['id' => 2, 'registration_number' => '87654321']
            );
        $this->clientMock->method('isConfigured')->willReturn(true);
        $this->clientMock->method('getAllInvoicesByIco')->willReturn([
            ['Id' => 1, 'DocumentNumber' => 'INV-001', 'IsPaid' => false, 'DateOfMaturity' => '2025-01-01'],
        ]);
        $this->invoiceRepoMock->method('upsertFromIdoklad');

        $result = $this->service->syncInvoicesForUser(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_synced']);
        $this->assertCount(2, $result['companies']);
    }

    public function testSyncInvoicesForUserHandlesNoCompanies(): void
    {
        $this->companyRepoMock->method('findByUserId')->willReturn([]);

        $result = $this->service->syncInvoicesForUser(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['total_synced']);
        $this->assertCount(0, $result['companies']);
    }

    // getInvoicesForUser tests

    public function testGetInvoicesForUserMapsFieldsCorrectly(): void
    {
        $this->invoiceRepoMock->method('findByUserId')->willReturn([
            [
                'id' => 1,
                'idoklad_id' => 12345,
                'document_number' => 'INV-001',
                'date_issued' => '2024-01-01',
                'date_due' => '2024-01-15',
                'total_amount' => 1000.50,
                'currency_code' => 'CZK',
                'variable_symbol' => '2024001',
                'payment_status' => 'unpaid',
                'company_name' => 'Test Company',
                'registration_number' => '12345678',
            ],
        ]);

        $result = $this->service->getInvoicesForUser(1);

        $this->assertCount(1, $result);
        $this->assertEquals('INV-001', $result[0]['id']);
        $this->assertEquals(1, $result[0]['dbId']);
        $this->assertEquals(12345, $result[0]['idokladId']);
        $this->assertEquals('2024-01-01', $result[0]['issued']);
        $this->assertEquals('2024-01-15', $result[0]['due']);
        $this->assertEquals(1000.50, $result[0]['amount']);
        $this->assertEquals('CZK', $result[0]['currency']);
        $this->assertEquals('2024001', $result[0]['varSymbol']);
        $this->assertEquals('unpaid', $result[0]['status']);
        $this->assertEquals('Test Company', $result[0]['companyName']);
        $this->assertEquals('12345678', $result[0]['ico']);
    }

    public function testGetInvoicesForUserCalculatesDaysRelative(): void
    {
        $futureDate = (new \DateTime('today'))->modify('+10 days')->format('Y-m-d');
        $this->invoiceRepoMock->method('findByUserId')->willReturn([
            [
                'id' => 1,
                'idoklad_id' => 1,
                'document_number' => 'INV-001',
                'date_issued' => '2024-01-01',
                'date_due' => $futureDate,
                'total_amount' => 1000,
                'currency_code' => 'CZK',
                'variable_symbol' => null,
                'payment_status' => 'unpaid',
                'company_name' => null,
                'registration_number' => null,
            ],
        ]);

        $result = $this->service->getInvoicesForUser(1);

        $this->assertEquals(10, $result[0]['daysRelative']);
    }

    public function testGetInvoicesForUserWithIcoFilter(): void
    {
        $this->invoiceRepoMock->expects($this->once())
            ->method('findByUserId')
            ->with(1, '12345678')
            ->willReturn([]);

        $this->service->getInvoicesForUser(1, '12345678');
    }

    // getInvoicePdf tests

    public function testGetInvoicePdfReturnsNullWhenUserDoesNotOwnInvoice(): void
    {
        $this->invoiceRepoMock->method('userOwnsInvoice')->willReturn(false);

        $result = $this->service->getInvoicePdf(1, 1);

        $this->assertNull($result);
    }

    public function testGetInvoicePdfReturnsNullWhenInvoiceNotFound(): void
    {
        $this->invoiceRepoMock->method('userOwnsInvoice')->willReturn(true);
        $this->invoiceRepoMock->method('findById')->willReturn(null);

        $result = $this->service->getInvoicePdf(1, 1);

        $this->assertNull($result);
    }

    public function testGetInvoicePdfReturnsPdfContent(): void
    {
        $this->invoiceRepoMock->method('userOwnsInvoice')->willReturn(true);
        $this->invoiceRepoMock->method('findById')->willReturn([
            'id' => 1,
            'idoklad_id' => 12345,
        ]);
        $this->clientMock->method('getInvoicePdf')->willReturn('%PDF-1.4 content');

        $result = $this->service->getInvoicePdf(1, 1);

        $this->assertEquals('%PDF-1.4 content', $result);
    }

    // getInvoiceFilename tests

    public function testGetInvoiceFilenameReturnsDefaultWhenNotFound(): void
    {
        $this->invoiceRepoMock->method('findById')->willReturn(null);

        $result = $this->service->getInvoiceFilename(1);

        $this->assertEquals('faktura.pdf', $result);
    }

    public function testGetInvoiceFilenameSanitizesDocumentNumber(): void
    {
        $this->invoiceRepoMock->method('findById')->willReturn([
            'id' => 1,
            'document_number' => 'INV/2024-001',
        ]);

        $result = $this->service->getInvoiceFilename(1);

        $this->assertEquals('faktura_INV_2024-001.pdf', $result);
    }

    public function testGetInvoiceFilenameHandlesNullDocumentNumber(): void
    {
        $this->invoiceRepoMock->method('findById')->willReturn([
            'id' => 1,
            'document_number' => null,
        ]);

        $result = $this->service->getInvoiceFilename(1);

        $this->assertEquals('faktura_faktura.pdf', $result);
    }

    // getLastSyncTime tests

    public function testGetLastSyncTimeReturnsTimestamp(): void
    {
        $this->invoiceRepoMock->method('getLastSyncTime')->willReturn('2024-01-15 12:00:00');

        $result = $this->service->getLastSyncTime(1);

        $this->assertEquals('2024-01-15 12:00:00', $result);
    }

    public function testGetLastSyncTimeReturnsNullWhenNeverSynced(): void
    {
        $this->invoiceRepoMock->method('getLastSyncTime')->willReturn(null);

        $result = $this->service->getLastSyncTime(1);

        $this->assertNull($result);
    }
}
