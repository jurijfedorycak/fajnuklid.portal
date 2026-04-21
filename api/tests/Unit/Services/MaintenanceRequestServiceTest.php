<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\CompanyRepository;
use App\Repositories\MaintenanceRequestRepository;
use App\Services\MailerService;
use App\Services\MaintenanceRequestService;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class MaintenanceRequestServiceTest extends TestCase
{
    private MaintenanceRequestService $service;
    private MockObject&MaintenanceRequestRepository $repoMock;
    private MockObject&CompanyRepository $companyRepoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoMock = $this->createMock(MaintenanceRequestRepository::class);
        $this->companyRepoMock = $this->createMock(CompanyRepository::class);

        $this->service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock);
    }

    protected function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    private function setConfigValues(array $values): void
    {
        $ref = new \ReflectionClass(Config::class);
        $ref->getProperty('config')->setValue(null, $values);
        $ref->getProperty('loaded')->setValue(null, true);
    }

    private function resetConfig(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $ref->getProperty('config')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
    }

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'client_id' => 5,
            'company_id' => 7,
            'created_by_user_id' => 10,
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'status' => 'prijato',
            'due_date' => null,
            'created_at' => '2026-04-01 09:00:00',
            'updated_at' => '2026-04-01 09:00:00',
            'created_by_email' => 'user@example.com',
            'company_name' => 'Acme',
            'company_ico' => '12345678',
        ], $overrides);
    }

    private function stubAttachmentsAndActivity(): void
    {
        $this->repoMock->method('findActivity')->willReturn([]);
        $this->repoMock->method('findAttachments')->willReturn([]);
    }

    // resolveClientIdForUser

    public function testResolveClientIdForUserReturnsClientIdFromFirstCompany(): void
    {
        $this->companyRepoMock->method('findByUserId')->willReturn([
            ['id' => 1, 'client_id' => 5, 'name' => 'Acme'],
        ]);

        $this->assertSame(5, $this->service->resolveClientIdForUser(10));
    }

    public function testResolveClientIdForUserReturnsNullWhenNoCompanies(): void
    {
        $this->companyRepoMock->method('findByUserId')->willReturn([]);

        $this->assertNull($this->service->resolveClientIdForUser(10));
    }

    // listForClient

    public function testListForClientFormatsRows(): void
    {
        $this->stubAttachmentsAndActivity();
        $this->repoMock->method('findByClientId')->willReturn([
            $this->makeRow(['id' => 1]),
            $this->makeRow(['id' => 2, 'status' => 'resi_se']),
        ]);

        $result = $this->service->listForClient(5);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['id']);
        $this->assertSame('Broken AC', $result[0]['title']);
        $this->assertSame('resi_se', $result[1]['status']);
        $this->assertArrayHasKey('createdAt', $result[0]);
        $this->assertArrayNotHasKey('created_at', $result[0]);
    }

    // getForClient

    public function testGetForClientReturnsRequestWithActivity(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $this->repoMock->method('findAttachments')->willReturn([]);
        $this->repoMock->method('findActivity')->willReturn([
            ['id' => 1, 'author_type' => 'system', 'author_name' => 'Systém', 'message' => 'Created', 'status_change' => 'prijato', 'created_at' => '2026-04-01 09:00:00'],
        ]);

        $result = $this->service->getForClient(1, 5);

        $this->assertSame(1, $result['id']);
        $this->assertCount(1, $result['activity']);
        $this->assertSame('system', $result['activity'][0]['authorType']);
    }

    public function testGetForClientThrowsWhenNotFound(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->getForClient(999, 5);
    }

    // create — validation

    public function testCreateThrowsValidationWhenTitleMissing(): void
    {
        try {
            $this->service->create(5, 10, [
                'description' => 'desc',
                'companyId' => 7,
            ], false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->getErrors());
        }
    }

    public function testCreateThrowsValidationWhenDescriptionMissing(): void
    {
        try {
            $this->service->create(5, 10, [
                'title' => 'Broken AC',
                'companyId' => 7,
            ], false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('description', $e->getErrors());
        }
    }

    public function testCreateThrowsValidationWhenCategoryInvalid(): void
    {
        try {
            $this->service->create(5, 10, [
                'title' => 'Broken AC',
                'description' => 'desc',
                'category' => 'nonexistent',
                'companyId' => 7,
            ], false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category', $e->getErrors());
        }
    }

    public function testCreateThrowsValidationWhenCompanyIdMissing(): void
    {
        try {
            $this->service->create(5, 10, [
                'title' => 'Broken AC',
                'description' => 'desc',
            ], false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('companyId', $e->getErrors());
        }
    }

    public function testCreateThrowsWhenCompanyDoesNotBelongToClient(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 99, 'client_id' => 999, 'name' => 'Other',
        ]);

        try {
            $this->service->create(5, 10, [
                'title' => 'Broken AC',
                'description' => 'desc',
                'category' => 'reklamace',
                'companyId' => 99,
            ], false);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('companyId', $e->getErrors());
        }
    }

    public function testCreateInsertsAndAddsActivity(): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 7, 'client_id' => 5, 'name' => 'Acme', 'registration_number' => '12345678',
        ]);

        $this->repoMock->expects($this->once())
            ->method('create')
            ->willReturn(42);

        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['request_id'] === 42 && $d['author_type'] === 'system'));

        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['id' => 42]));
        $this->stubAttachmentsAndActivity();

        $result = $this->service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], false);

        $this->assertSame(42, $result['id']);
    }

    // clientReject

    public function testClientRejectThrowsWhenStatusNotAwaiting(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'resi_se']));

        $this->expectException(ValidationException::class);
        $this->service->clientReject(1, 5, 10, 'Klient', 'protože');
    }

    public function testClientRejectRequiresComment(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'ceka_na_potvrzeni']));

        try {
            $this->service->clientReject(1, 5, 10, 'Klient', '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->getErrors());
        }
    }

    public function testClientRejectRevertsToResiSe(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturnOnConsecutiveCalls(
            $this->makeRow(['status' => 'ceka_na_potvrzeni']),
            $this->makeRow(['status' => 'resi_se'])
        );
        $this->repoMock->expects($this->once())->method('updateStatus')->with(1, 'resi_se');
        $this->repoMock->expects($this->once())->method('addActivity');
        $this->stubAttachmentsAndActivity();

        $result = $this->service->clientReject(1, 5, 10, 'Klient', 'Stále neteče voda');
        $this->assertSame('resi_se', $result['status']);
    }

    // clientCancel

    public function testClientCancelThrowsWhenNotInPrijato(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'resi_se']));
        $this->expectException(ValidationException::class);
        $this->service->clientCancel(1, 5, 10, 'Klient');
    }

    public function testClientCancelSoftDeletes(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'prijato']));
        $this->repoMock->expects($this->once())->method('addActivity');
        $this->repoMock->expects($this->once())->method('softDelete')->with(1);

        $this->service->clientCancel(1, 5, 10, 'Klient');
    }

    // clientConfirm

    public function testClientConfirmThrowsWhenNotFound(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->clientConfirm(1, 5, 10, 'user@example.com');
    }

    public function testClientConfirmThrowsWhenStatusNotAwaiting(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'resi_se']));

        $this->expectException(ValidationException::class);
        $this->service->clientConfirm(1, 5, 10, 'user@example.com');
    }

    public function testClientConfirmUpdatesStatusAndAddsActivity(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturnOnConsecutiveCalls(
            $this->makeRow(['status' => 'ceka_na_potvrzeni']),
            $this->makeRow(['status' => 'vyreseno'])
        );
        $this->repoMock->expects($this->once())
            ->method('updateStatus')
            ->with(1, 'vyreseno');
        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['author_type'] === 'client' && $d['status_change'] === 'vyreseno'));
        $this->stubAttachmentsAndActivity();

        $result = $this->service->clientConfirm(1, 5, 10, 'user@example.com');

        $this->assertSame('vyreseno', $result['status']);
    }

    // adminUpdate

    public function testAdminUpdateThrowsWhenNotFound(): void
    {
        $this->repoMock->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->adminUpdate(1, 1, 'admin@example.com', ['status' => 'resi_se']);
    }

    public function testAdminUpdateThrowsWhenStatusInvalid(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());

        $this->expectException(ValidationException::class);
        $this->service->adminUpdate(1, 1, 'admin@example.com', ['status' => 'bad_status']);
    }

    public function testAdminUpdateChangesStatusAndLogsActivity(): void
    {
        $this->repoMock->method('findById')->willReturnOnConsecutiveCalls(
            $this->makeRow(['status' => 'prijato']),
            $this->makeRow(['status' => 'resi_se'])
        );
        $this->repoMock->expects($this->once())
            ->method('update')
            ->with(1, $this->callback(fn ($d) => $d['status'] === 'resi_se'));
        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['author_type'] === 'admin' && $d['status_change'] === 'resi_se'));
        $this->stubAttachmentsAndActivity();

        $result = $this->service->adminUpdate(1, 1, 'admin@example.com', ['status' => 'resi_se']);

        $this->assertSame('resi_se', $result['status']);
    }

    public function testAdminUpdateDoesNotLogActivityWhenStatusUnchanged(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow(['status' => 'prijato']));
        $this->repoMock->expects($this->once())->method('update');
        $this->repoMock->expects($this->never())->method('addActivity');
        $this->stubAttachmentsAndActivity();

        $this->service->adminUpdate(1, 1, 'admin@example.com', ['status' => 'prijato', 'dueDate' => '2026-05-01']);
    }

    // adminAddActivity

    public function testAdminAddActivityThrowsWhenNotFound(): void
    {
        $this->repoMock->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->adminAddActivity(1, 1, 'admin@example.com', 'Hello');
    }

    public function testAdminAddActivityThrowsWhenMessageEmpty(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());

        $this->expectException(ValidationException::class);
        $this->service->adminAddActivity(1, 1, 'admin@example.com', '   ');
    }

    public function testAdminAddActivityInsertsActivity(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['author_type'] === 'admin' && $d['message'] === 'Hello' && $d['is_internal'] === false));
        $this->stubAttachmentsAndActivity();

        $this->service->adminAddActivity(1, 1, 'admin@example.com', 'Hello');
    }

    public function testAdminAddActivityWithInternalFlagPropagates(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['is_internal'] === true));
        $this->stubAttachmentsAndActivity();

        $this->service->adminAddActivity(1, 1, 'admin@example.com', 'Internal note', true);
    }

    public function testGetForClientFiltersInternalActivity(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $this->repoMock->method('findAttachments')->willReturn([]);
        $this->repoMock->expects($this->once())
            ->method('findActivity')
            ->with(1, false)
            ->willReturn([]);

        $this->service->getForClient(1, 5);
    }

    public function testGetForAdminIncludesInternalActivity(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $this->repoMock->method('findAttachments')->willReturn([]);
        $this->repoMock->expects($this->once())
            ->method('findActivity')
            ->with(1, true)
            ->willReturn([]);

        $this->service->getForAdmin(1);
    }

    // adminDelete

    public function testAdminDeleteThrowsWhenNotFound(): void
    {
        $this->repoMock->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->adminDelete(999);
    }

    public function testAdminDeleteSoftDeletes(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())->method('softDelete')->with(1);

        $this->service->adminDelete(1);
    }

    // create — notifications

    private function setupSuccessfulCreate(array $rowOverrides = []): void
    {
        $this->companyRepoMock->method('findById')->willReturn([
            'id' => 7, 'client_id' => 5, 'name' => 'Acme', 'registration_number' => '12345678',
        ]);
        $this->repoMock->method('create')->willReturn(42);
        $this->repoMock->method('findByIdForClient')
            ->willReturn($this->makeRow(array_merge(['id' => 42], $rowOverrides)));
        $this->stubAttachmentsAndActivity();
    }

    public function testCreateNotifiesEachAdminFromConfig(): void
    {
        $this->setConfigValues(['ADMIN_EMAILS' => 'jurij.fedorycak@fajnuklid.cz,vaseuklidovka@fajnuklid.cz']);
        $this->setupSuccessfulCreate();

        $calls = [];
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function ($to, $subject, $body) use (&$calls) {
                $calls[] = ['to' => $to, 'subject' => $subject];
                return true;
            });

        $service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock, $mailer);
        $service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], true);

        $recipients = array_column($calls, 'to');
        $this->assertNotContains('user@example.com', $recipients, 'Request author must not receive email');
        $this->assertContains('jurij.fedorycak@fajnuklid.cz', $recipients);
        $this->assertContains('vaseuklidovka@fajnuklid.cz', $recipients);

        foreach ($calls as $call) {
            $this->assertStringStartsWith('Nový požadavek: ', $call['subject']);
        }
    }

    public function testCreateDoesNotSendEmailToAuthorEvenWhenListedAsAdmin(): void
    {
        $this->setConfigValues(['ADMIN_EMAILS' => 'User@Example.com,admin@fajnuklid.cz']);
        $this->setupSuccessfulCreate();

        $recipients = [];
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function ($to) use (&$recipients) {
                $recipients[] = $to;
                return true;
            });

        $service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock, $mailer);
        $service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], true);

        $this->assertSame(['admin@fajnuklid.cz'], $recipients);
    }

    public function testCreateFiltersInvalidAdminEmails(): void
    {
        $this->setConfigValues(['ADMIN_EMAILS' => 'not-an-email, ,admin@fajnuklid.cz']);
        $this->setupSuccessfulCreate();

        $recipients = [];
        $mailer = $this->createMock(MailerService::class);
        $mailer->method('send')->willReturnCallback(function ($to) use (&$recipients) {
            $recipients[] = $to;
            return true;
        });

        $service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock, $mailer);
        $service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], true);

        $this->assertSame(['admin@fajnuklid.cz'], $recipients);
    }

    public function testCreateSendsNoEmailsWhenNoAdminsConfigured(): void
    {
        $this->setConfigValues(['ADMIN_EMAILS' => '']);
        $this->setupSuccessfulCreate();

        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->never())->method('send');

        $service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock, $mailer);
        $service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], true);
    }

    public function testCreateMailerFailureDoesNotAbortRequestCreation(): void
    {
        $this->setConfigValues(['ADMIN_EMAILS' => 'admin@fajnuklid.cz']);
        $this->setupSuccessfulCreate();

        $mailer = $this->createMock(MailerService::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        $service = new MaintenanceRequestService($this->repoMock, $this->companyRepoMock, $mailer);
        $result = $service->create(5, 10, [
            'title' => 'Broken AC',
            'category' => 'reklamace',
            'description' => 'It does not cool',
            'companyId' => 7,
        ], true);

        $this->assertSame(42, $result['id']);
    }
}
