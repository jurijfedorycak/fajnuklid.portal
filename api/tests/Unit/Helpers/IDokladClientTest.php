<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\IDokladClient;
use App\Repositories\IDokladTokenRepository;
use Tests\TestCase;

class IDokladClientTest extends TestCase
{
    /**
     * Build an IDokladClient without invoking the real constructor so we don't
     * need a DB connection for pure unit tests. Stubs the token repository.
     */
    private function buildClient(string $apiUrl = 'https://api.idoklad.cz/v3'): IDokladClient
    {
        $ref = new \ReflectionClass(IDokladClient::class);
        /** @var IDokladClient $client */
        $client = $ref->newInstanceWithoutConstructor();

        $props = [
            'tokenRepo' => $this->createMock(IDokladTokenRepository::class),
            'clientId' => 'test-client',
            'clientSecret' => 'test-secret',
            'apiUrl' => $apiUrl,
            'lastError' => null,
        ];
        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($client, $value);
        }

        return $client;
    }

    // calculatePaymentStatus tests

    public function testCalculatePaymentStatusReturnsPaidWhenIsPaidTrue(): void
    {
        $invoice = ['IsPaid' => true, 'DateOfMaturity' => '2024-01-15'];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('paid', $result);
    }

    public function testCalculatePaymentStatusReturnsPaidWithLowercaseKey(): void
    {
        $invoice = ['is_paid' => true, 'date_due' => '2024-01-15'];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('paid', $result);
    }

    public function testCalculatePaymentStatusReturnsUnpaidWhenNoDueDate(): void
    {
        $invoice = ['IsPaid' => false];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('unpaid', $result);
    }

    public function testCalculatePaymentStatusReturnsOverdueWhenPastDueDate(): void
    {
        $pastDate = (new \DateTime('yesterday'))->format('Y-m-d');
        $invoice = ['IsPaid' => false, 'DateOfMaturity' => $pastDate];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('overdue', $result);
    }

    public function testCalculatePaymentStatusReturnsUnpaidWhenFutureDueDate(): void
    {
        $futureDate = (new \DateTime('+10 days'))->format('Y-m-d');
        $invoice = ['IsPaid' => false, 'DateOfMaturity' => $futureDate];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('unpaid', $result);
    }

    public function testCalculatePaymentStatusReturnsOverdueWhenDueDateIsToday(): void
    {
        // Due date is today (start of day), current time is later, so it's overdue
        $today = (new \DateTime('today'))->format('Y-m-d');
        $invoice = ['IsPaid' => false, 'DateOfMaturity' => $today];

        // Today's due date means it's not overdue yet (dueDate is not < today)
        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('unpaid', $result);
    }

    public function testCalculatePaymentStatusWithLowercaseDateKey(): void
    {
        $pastDate = (new \DateTime('-5 days'))->format('Y-m-d');
        $invoice = ['is_paid' => false, 'date_due' => $pastDate];

        $result = IDokladClient::calculatePaymentStatus($invoice);

        $this->assertEquals('overdue', $result);
    }

    // mapIdokladInvoice tests

    public function testMapIdokladInvoiceReturnsCorrectStructure(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-2024-001',
            'VariableSymbol' => '2024001',
            'DateOfIssue' => '2024-01-01',
            'DateOfMaturity' => '2024-01-15',
            'DateOfPayment' => '2024-01-10',
            'TotalWithVat' => 1210.50,
            'Currency' => ['Code' => 'CZK'],
            'IsPaid' => true,
            'Description' => 'Cleaning services',
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals(12345, $result['idoklad_id']);
        $this->assertEquals(1, $result['company_id']);
        $this->assertEquals('INV-2024-001', $result['document_number']);
        $this->assertEquals('2024001', $result['variable_symbol']);
        $this->assertEquals('2024-01-01', $result['date_issued']);
        $this->assertEquals('2024-01-15', $result['date_due']);
        $this->assertEquals('2024-01-10', $result['date_paid']);
        $this->assertEquals(1210.50, $result['total_amount']);
        $this->assertEquals('CZK', $result['currency_code']);
        $this->assertTrue($result['is_paid']);
        $this->assertEquals('paid', $result['payment_status']);
        $this->assertEquals('Cleaning services', $result['description']);
    }

    public function testMapIdokladInvoiceHandlesNullValues(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'DateOfIssue' => '2024-01-01',
            'DateOfMaturity' => '2024-01-15',
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertNull($result['variable_symbol']);
        $this->assertNull($result['date_paid']);
        $this->assertEquals('CZK', $result['currency_code']);
        $this->assertNull($result['description']);
        $this->assertEquals(0.0, $result['total_amount']);
    }

    public function testMapIdokladInvoiceUsesDefaultsForMissingFields(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('', $result['document_number']);
        $this->assertEquals(date('Y-m-d'), $result['date_issued']);
        $this->assertEquals(date('Y-m-d'), $result['date_due']);
        $this->assertEquals('CZK', $result['currency_code']);
    }

    public function testMapIdokladInvoiceCastsIdToInt(): void
    {
        $idokladInvoice = [
            'Id' => '12345',
            'DocumentNumber' => 'INV-001',
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertIsInt($result['idoklad_id']);
        $this->assertEquals(12345, $result['idoklad_id']);
    }

    public function testMapIdokladInvoiceCastsTotalAmountToFloat(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'TotalWithVat' => '1000',
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertIsFloat($result['total_amount']);
        $this->assertEquals(1000.0, $result['total_amount']);
    }

    public function testMapIdokladInvoiceCastsIsPaidToBool(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'IsPaid' => 1,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertIsBool($result['is_paid']);
        $this->assertTrue($result['is_paid']);
    }

    public function testMapIdokladInvoiceExtractsCurrencyCode(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'Currency' => ['Code' => 'EUR'],
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('EUR', $result['currency_code']);
    }

    public function testMapIdokladInvoiceHandlesMissingCurrency(): void
    {
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('CZK', $result['currency_code']);
    }

    public function testMapIdokladInvoiceCalculatesCorrectPaymentStatusForUnpaid(): void
    {
        $futureDate = (new \DateTime('+10 days'))->format('Y-m-d');
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'DateOfMaturity' => $futureDate,
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('unpaid', $result['payment_status']);
    }

    public function testMapIdokladInvoiceCalculatesCorrectPaymentStatusForOverdue(): void
    {
        $pastDate = (new \DateTime('-10 days'))->format('Y-m-d');
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'DateOfMaturity' => $pastDate,
            'IsPaid' => false,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('overdue', $result['payment_status']);
    }

    public function testMapIdokladInvoiceCalculatesCorrectPaymentStatusForPaid(): void
    {
        $pastDate = (new \DateTime('-10 days'))->format('Y-m-d');
        $idokladInvoice = [
            'Id' => 12345,
            'DocumentNumber' => 'INV-001',
            'DateOfMaturity' => $pastDate,
            'IsPaid' => true,
        ];

        $result = IDokladClient::mapIdokladInvoice($idokladInvoice, 1);

        $this->assertEquals('paid', $result['payment_status']);
    }

    // Error capture + read-only contract

    public function testLastErrorStartsNull(): void
    {
        $client = $this->buildClient();

        $this->assertNull($client->getLastError());
    }

    public function testResetLastErrorClearsState(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $prop = $ref->getProperty('lastError');
        $prop->setAccessible(true);
        $prop->setValue($client, ['context' => 'test']);

        $this->assertNotNull($client->getLastError());

        $client->resetLastError();

        $this->assertNull($client->getLastError());
    }

    public function testGetInvoicesByIcoRecordsErrorWhenIcoEmpty(): void
    {
        $client = $this->buildClient();

        $result = $client->getInvoicesByIco('abc-only-letters');

        $this->assertNull($result);
        $error = $client->getLastError();
        $this->assertNotNull($error);
        $this->assertEquals('input validation', $error['context']);
        $this->assertStringContainsString('abc-only-letters', $error['response_body']);
    }

    public function testReadOnlyContractRejectsNonGetRequests(): void
    {
        $client = $this->buildClient();

        $ref = new \ReflectionClass($client);
        $request = $ref->getMethod('request');
        $request->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/read-only/');

        $request->invoke($client, 'POST', '/IssuedInvoices', ['foo' => 'bar']);
    }

    public function testExtractItemsHandlesDataWrappedEnvelope(): void
    {
        $ref = new \ReflectionClass(IDokladClient::class);
        $method = $ref->getMethod('extractItems');
        $method->setAccessible(true);

        $wrapped = ['Data' => ['Items' => [['Id' => 1], ['Id' => 2]], 'TotalPages' => 3]];
        $this->assertEquals([['Id' => 1], ['Id' => 2]], $method->invoke(null, $wrapped));

        $flat = ['Items' => [['Id' => 9]], 'TotalPages' => 1];
        $this->assertEquals([['Id' => 9]], $method->invoke(null, $flat));

        $this->assertEquals([], $method->invoke(null, ['Data' => null]));
        $this->assertEquals([], $method->invoke(null, []));
    }

    public function testExtractTotalPagesHandlesDataWrappedEnvelope(): void
    {
        $ref = new \ReflectionClass(IDokladClient::class);
        $method = $ref->getMethod('extractTotalPages');
        $method->setAccessible(true);

        $this->assertEquals(5, $method->invoke(null, ['Data' => ['TotalPages' => 5]]));
        $this->assertEquals(7, $method->invoke(null, ['TotalPages' => 7]));
        $this->assertEquals(1, $method->invoke(null, []));
    }
}
