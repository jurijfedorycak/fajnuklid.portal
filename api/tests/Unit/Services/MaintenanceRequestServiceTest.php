<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\MaintenanceRequestRepository;
use App\Services\MailerService;
use App\Services\MaintenanceRequestService;
use App\Services\R2StorageService;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class MaintenanceRequestServiceTest extends TestCase
{
    private MaintenanceRequestService $service;
    private MockObject&MaintenanceRequestRepository $repoMock;
    private MockObject&CompanyRepository $companyRepoMock;
    private MockObject&ClientRepository $clientRepoMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoMock = $this->createMock(MaintenanceRequestRepository::class);
        $this->companyRepoMock = $this->createMock(CompanyRepository::class);
        $this->clientRepoMock = $this->createMock(ClientRepository::class);

        $this->service = new MaintenanceRequestService(
            $this->repoMock,
            $this->companyRepoMock,
            null,
            null,
            $this->clientRepoMock
        );
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
            'source' => 'portal',
            'visibility' => 'client',
            'status' => 'prijato',
            'due_date' => null,
            'record_date' => null,
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

    public function testListForClientPassesDateToRepository(): void
    {
        $this->repoMock->expects($this->once())
            ->method('findByClientId')
            ->with(5, null, null, '2026-05-05')
            ->willReturn([]);

        $this->service->listForClient(5, null, null, '2026-05-05');
    }

    public function testListForClientThrowsOnInvalidDate(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->listForClient(5, null, null, '05.05.2026');
    }

    public function testListForClientMapsUnreadCountToNewMessages(): void
    {
        $this->repoMock->method('findByClientId')->willReturn([
            $this->makeRow(['id' => 1, 'unread_count' => '2']),
            $this->makeRow(['id' => 2, 'unread_count' => '0']),
        ]);

        $result = $this->service->listForClient(5);

        $this->assertSame(2, $result[0]['newMessages']);
        $this->assertSame(0, $result[1]['newMessages']);
    }

    public function testFormatRowWithoutUnreadCountOmitsNewMessages(): void
    {
        $this->repoMock->method('findByClientId')->willReturn([$this->makeRow()]);

        $result = $this->service->listForClient(5);

        $this->assertArrayNotHasKey('newMessages', $result[0]);
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

    public function testGetForClientWithMarkReadMarksAdminMessagesRead(): void
    {
        $this->stubAttachmentsAndActivity();
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())
            ->method('markMessagesRead')
            ->with(1, 'admin');

        $this->service->getForClient(1, 5, true);
    }

    public function testGetForClientByDefaultDoesNotMarkMessagesRead(): void
    {
        $this->stubAttachmentsAndActivity();
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $this->repoMock->expects($this->never())->method('markMessagesRead');

        $this->service->getForClient(1, 5);
    }

    public function testGetForClientNotFoundDoesNotMarkMessagesRead(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn(null);
        $this->repoMock->expects($this->never())->method('markMessagesRead');

        $this->expectException(NotFoundException::class);
        $this->service->getForClient(999, 5, true);
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

    public function testClientRejectThrowsWhenStatusNotResiSe(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'prijato']));

        $this->expectException(ValidationException::class);
        $this->service->clientReject(1, 5, 10, 'Klient', 'protože');
    }

    public function testClientRejectRequiresComment(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'resi_se']));

        try {
            $this->service->clientReject(1, 5, 10, 'Klient', '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->getErrors());
        }
    }

    public function testClientRejectAddsCommentWithoutChangingStatus(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'resi_se']));
        $this->repoMock->expects($this->never())->method('updateStatus');
        $this->repoMock->expects($this->once())
            ->method('addActivity')
            ->with($this->callback(fn ($d) => $d['author_type'] === 'client' && $d['message'] === 'Stále neteče voda' && $d['status_change'] === null));
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

    public function testClientConfirmThrowsWhenStatusNotResiSe(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow(['status' => 'prijato']));

        $this->expectException(ValidationException::class);
        $this->service->clientConfirm(1, 5, 10, 'user@example.com');
    }

    public function testClientConfirmUpdatesStatusAndAddsActivity(): void
    {
        $this->repoMock->method('findByIdForClient')->willReturnOnConsecutiveCalls(
            $this->makeRow(['status' => 'resi_se']),
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

    public function testCreateNotifiesHardcodedRecipient(): void
    {
        $this->setupSuccessfulCreate();

        $calls = [];
        $mailer = $this->createMock(MailerService::class);
        $mailer->expects($this->once())
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

        $this->assertSame('jurij.fedorycak@fajnuklid.cz', $calls[0]['to']);
        $this->assertStringStartsWith('Nový požadavek: ', $calls[0]['subject']);
    }

    public function testCreateDoesNotSendEmailWhenAuthorIsRecipient(): void
    {
        $this->setupSuccessfulCreate(['created_by_email' => 'Jurij.Fedorycak@Fajnuklid.cz']);

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

    // calendarForClient

    public function testCalendarForClientGroupsByDateAndStatus(): void
    {
        $this->repoMock->method('countByDayForClient')->willReturn([
            ['date' => '2026-05-06', 'status' => 'prijato', 'count' => 2],
            ['date' => '2026-05-06', 'status' => 'resi_se', 'count' => 1],
            ['date' => '2026-05-07', 'status' => 'vyreseno', 'count' => 3],
        ]);

        $result = $this->service->calendarForClient(5, 2026, 5);

        $this->assertCount(2, $result);
        $this->assertSame('2026-05-06', $result[0]['date']);
        $this->assertSame(3, $result[0]['total']);
        $this->assertSame(['prijato' => 2, 'resi_se' => 1], $result[0]['statuses']);
        $this->assertSame('2026-05-07', $result[1]['date']);
        $this->assertSame(3, $result[1]['total']);
        $this->assertSame(['vyreseno' => 3], $result[1]['statuses']);
    }

    public function testCalendarForClientReturnsEmptyWhenNoRows(): void
    {
        $this->repoMock->method('countByDayForClient')->willReturn([]);

        $this->assertSame([], $this->service->calendarForClient(5, 2026, 5));
    }

    public function testCalendarForClientThrowsWhenMonthOutOfRange(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calendarForClient(5, 2026, 13);
    }

    public function testCalendarForClientThrowsWhenMonthZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->calendarForClient(5, 2026, 0);
    }

    // adminCreate

    private function adminCreateInput(array $overrides = []): array
    {
        return array_merge([
            'clientId' => 5,
            'title' => 'Telefonický požadavek',
            'description' => 'Klient žádá úklid skladu navíc.',
        ], $overrides);
    }

    public function testAdminCreateThrowsWhenClientIdMissing(): void
    {
        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['clientId' => null]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('clientId', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenTitleAndDescriptionMissing(): void
    {
        try {
            $this->service->adminCreate(1, 'admin@example.com', ['clientId' => 5]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->getErrors());
            $this->assertArrayHasKey('description', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenSourceInvalid(): void
    {
        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['source' => 'pigeon']));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenVisibilityInvalid(): void
    {
        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['visibility' => 'public']));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('visibility', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenRecordDateInvalid(): void
    {
        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['recordDate' => '06/30/2026']));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recordDate', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenRecordDateInFuture(): void
    {
        $futureDate = (new \DateTime('tomorrow'))->format('Y-m-d');

        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['recordDate' => $futureDate]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recordDate', $e->getErrors());
            $this->assertStringContainsString('budoucnosti', $e->getErrors()['recordDate'][0]);
        }
    }

    public function testAdminUpdateThrowsWhenRecordDateInFuture(): void
    {
        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $futureDate = (new \DateTime('tomorrow'))->format('Y-m-d');

        try {
            $this->service->adminUpdate(1, 1, 'admin@example.com', ['recordDate' => $futureDate]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recordDate', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenClientNotFound(): void
    {
        $this->clientRepoMock->method('findById')->willReturn(null);

        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('clientId', $e->getErrors());
        }
    }

    public function testAdminCreateThrowsWhenCompanyDoesNotBelongToClient(): void
    {
        $this->clientRepoMock->method('findById')->willReturn(['id' => 5, 'display_name' => 'Acme']);
        $this->companyRepoMock->method('findById')->willReturn(['id' => 9, 'client_id' => 999]);

        try {
            $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['companyId' => 9]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('companyId', $e->getErrors());
        }
    }

    public function testAdminCreateClientVisibleRecordStoresFieldsAndAddsBothNotes(): void
    {
        $this->clientRepoMock->method('findById')->willReturn(['id' => 5, 'display_name' => 'Acme']);

        $created = null;
        $this->repoMock->expects($this->once())
            ->method('create')
            ->willReturnCallback(function ($d) use (&$created) {
                $created = $d;
                return 42;
            });

        $activities = [];
        $this->repoMock->method('addActivity')->willReturnCallback(function ($d) use (&$activities) {
            $activities[] = $d;
            return count($activities);
        });

        $this->repoMock->method('findById')->willReturn($this->makeRow([
            'id' => 42,
            'source' => 'whatsapp',
            'visibility' => 'client',
            'record_date' => '2026-06-25',
        ]));
        $this->stubAttachmentsAndActivity();

        $result = $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput([
            'source' => 'whatsapp',
            'visibility' => 'client',
            'recordDate' => '2026-06-25',
            'companyId' => null,
        ]));

        // Stored with the chosen channel/visibility/date, no company.
        $this->assertSame('whatsapp', $created['source']);
        $this->assertSame('client', $created['visibility']);
        $this->assertSame('2026-06-25', $created['record_date']);
        $this->assertNull($created['company_id']);
        $this->assertSame('prijato', $created['status']);

        // Internal provenance note + client-visible creation note.
        $this->assertCount(2, $activities);
        $this->assertTrue($activities[0]['is_internal']);
        $this->assertStringContainsString('WhatsApp', $activities[0]['message']);
        $this->assertFalse($activities[1]['is_internal']);
        $this->assertSame('prijato', $activities[1]['status_change']);

        $this->assertSame(42, $result['id']);
        $this->assertSame('whatsapp', $result['source']);
        $this->assertSame('2026-06-25', $result['recordDate']);
    }

    public function testAdminCreateInternalRecordAddsOnlyInternalNote(): void
    {
        $this->clientRepoMock->method('findById')->willReturn(['id' => 5, 'display_name' => 'Acme']);
        $this->repoMock->method('create')->willReturn(42);

        $activities = [];
        $this->repoMock->method('addActivity')->willReturnCallback(function ($d) use (&$activities) {
            $activities[] = $d;
            return count($activities);
        });

        $this->repoMock->method('findById')->willReturn($this->makeRow([
            'id' => 42,
            'visibility' => 'internal',
        ]));
        $this->stubAttachmentsAndActivity();

        $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput([
            'source' => 'phone',
            'visibility' => 'internal',
        ]));

        $this->assertCount(1, $activities);
        $this->assertTrue($activities[0]['is_internal']);
        $this->assertSame('prijato', $activities[0]['status_change']);
    }

    public function testAdminCreateValidatesCompanyBelongsThenInsertsWithCompany(): void
    {
        $this->clientRepoMock->method('findById')->willReturn(['id' => 5, 'display_name' => 'Acme']);
        $this->companyRepoMock->method('findById')->willReturn(['id' => 7, 'client_id' => 5, 'name' => 'Acme']);

        $created = null;
        $this->repoMock->method('create')->willReturnCallback(function ($d) use (&$created) {
            $created = $d;
            return 42;
        });
        $this->repoMock->method('findById')->willReturn($this->makeRow(['id' => 42]));
        $this->stubAttachmentsAndActivity();

        $this->service->adminCreate(1, 'admin@example.com', $this->adminCreateInput(['companyId' => 7]));

        $this->assertSame(7, $created['company_id']);
    }

    // attachments

    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF_BYTES = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n";

    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $tmp) {
            @unlink($tmp);
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    private function makeServiceWithStorage(MockObject&R2StorageService $storage): MaintenanceRequestService
    {
        return new MaintenanceRequestService(
            $this->repoMock,
            $this->companyRepoMock,
            null,
            $storage,
            $this->clientRepoMock
        );
    }

    /**
     * Real bytes on disk because storeAttachment() sniffs the mime via finfo
     * instead of trusting the client-supplied type. sizeOverride lets the
     * oversize test declare 10 MB+ without writing it.
     */
    private function makeUploadedFile(string $bytes, string $name, ?int $sizeOverride = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, $bytes);
        $this->tmpFiles[] = $tmp;

        return [
            'error' => UPLOAD_ERR_OK,
            'size' => $sizeOverride ?? strlen($bytes),
            'tmp_name' => $tmp,
            'name' => $name,
        ];
    }

    public function testAddClientAttachmentStoresFileAndReturnsPayload(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $file = $this->makeUploadedFile(self::PDF_BYTES, 'foto.pdf');

        $this->repoMock->method('findByIdForClient')->with(1, 5)->willReturn($this->makeRow());
        $this->repoMock->method('countAttachments')->with(1, 'before')->willReturn(0);

        $storage->expects($this->once())
            ->method('upload')
            ->with('maintenance-request-attachments', $file['tmp_name'], 'foto.pdf', 'application/pdf')
            ->willReturn('maintenance-request-attachments/foto_abc123.pdf');
        $storage->method('resolveProxyUrl')->willReturn('https://api.test/storage/file?key=x&sig=y');

        $this->repoMock->expects($this->once())
            ->method('addAttachment')
            ->with($this->callback(fn (array $d) =>
                $d['request_id'] === 1
                && $d['phase'] === 'before'
                && $d['file_path'] === 'maintenance-request-attachments/foto_abc123.pdf'
                && $d['original_filename'] === 'foto.pdf'
                && $d['mime_type'] === 'application/pdf'
                && $d['size_bytes'] === $file['size']
                && $d['uploaded_by_user_id'] === 10))
            ->willReturn(77);

        $result = $service->addClientAttachment(1, 5, 10, $file);

        $this->assertSame([
            'id' => 77,
            'phase' => 'before',
            'url' => 'https://api.test/storage/file?key=x&sig=y',
            'filename' => 'foto.pdf',
            'mimeType' => 'application/pdf',
            'sizeBytes' => $file['size'],
        ], $result);
    }

    public function testAddClientAttachmentThrowsWhenRequestNotFound(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findByIdForClient')->willReturn(null);
        $storage->expects($this->never())->method('upload');

        $this->expectException(NotFoundException::class);
        $service->addClientAttachment(1, 5, 10, $this->makeUploadedFile(self::PDF_BYTES, 'a.pdf'));
    }

    public function testAddClientAttachmentRejectsFailedUpload(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $storage->expects($this->never())->method('upload');
        $file = $this->makeUploadedFile(self::PDF_BYTES, 'a.pdf');
        $file['error'] = UPLOAD_ERR_PARTIAL;

        $this->expectException(ValidationException::class);
        $service->addClientAttachment(1, 5, 10, $file);
    }

    public function testAddClientAttachmentRejectsOversizeFile(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $storage->expects($this->never())->method('upload');
        $file = $this->makeUploadedFile(self::PDF_BYTES, 'big.pdf', MaintenanceRequestService::ATTACHMENT_MAX_BYTES + 1);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('příliš velký');
        $service->addClientAttachment(1, 5, 10, $file);
    }

    public function testAddClientAttachmentRejectsDisallowedMimeType(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $storage->expects($this->never())->method('upload');
        $file = $this->makeUploadedFile('just some plain text', 'notes.txt');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nepodporovaný typ');
        $service->addClientAttachment(1, 5, 10, $file);
    }

    public function testAddClientAttachmentRejectsWhenPhaseLimitReached(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findByIdForClient')->willReturn($this->makeRow());
        $this->repoMock->method('countAttachments')->with(1, 'before')
            ->willReturn(MaintenanceRequestService::ATTACHMENT_MAX_PER_REQUEST);
        $storage->expects($this->never())->method('upload');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Maximální počet');
        $service->addClientAttachment(1, 5, 10, $this->makeUploadedFile(self::PDF_BYTES, 'a.pdf'));
    }

    public function testAddAdminAttachmentDefaultsToBeforePhase(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $file = $this->makeUploadedFile(base64_decode(self::PNG_1PX), 'foto.png');

        $this->repoMock->method('findById')->with(1)->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())->method('countAttachments')->with(1, 'before')->willReturn(0);
        $storage->method('upload')->willReturn('maintenance-request-attachments/foto_abc.png');
        $storage->method('resolveProxyUrl')->willReturn('https://api.test/storage/file?key=x&sig=y');

        $stored = null;
        $this->repoMock->expects($this->once())
            ->method('addAttachment')
            ->willReturnCallback(function (array $d) use (&$stored) {
                $stored = $d;
                return 78;
            });

        $result = $service->addAdminAttachment(1, 99, $file);

        $this->assertSame('before', $stored['phase']);
        $this->assertSame(99, $stored['uploaded_by_user_id']);
        $this->assertSame('image/png', $stored['mime_type']);
        $this->assertSame('before', $result['phase']);
        $this->assertSame(78, $result['id']);
    }

    public function testAddAdminAttachmentAcceptsExplicitAfterPhase(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);

        $this->repoMock->method('findById')->willReturn($this->makeRow());
        $this->repoMock->expects($this->once())->method('countAttachments')->with(1, 'after')->willReturn(0);
        $storage->method('upload')->willReturn('maintenance-request-attachments/po_abc.pdf');
        $storage->method('resolveProxyUrl')->willReturn('https://api.test/u');

        $stored = null;
        $this->repoMock->method('addAttachment')->willReturnCallback(function (array $d) use (&$stored) {
            $stored = $d;
            return 79;
        });

        $result = $service->addAdminAttachment(1, 99, $this->makeUploadedFile(self::PDF_BYTES, 'po.pdf'), 'after');

        $this->assertSame('after', $stored['phase']);
        $this->assertSame('after', $result['phase']);
    }

    public function testAddAdminAttachmentThrowsWhenRequestMissingOrDeleted(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);
        $this->repoMock->method('findById')->willReturn(null);
        $storage->expects($this->never())->method('upload');

        $this->expectException(NotFoundException::class);
        $service->addAdminAttachment(1, 99, $this->makeUploadedFile(self::PDF_BYTES, 'a.pdf'));
    }

    public function testAddAdminAttachmentWorksOnInternalRecord(): void
    {
        $storage = $this->createMock(R2StorageService::class);
        $service = $this->makeServiceWithStorage($storage);

        $this->repoMock->method('findById')->willReturn($this->makeRow(['visibility' => 'internal']));
        $this->repoMock->method('countAttachments')->willReturn(0);
        $storage->method('upload')->willReturn('maintenance-request-attachments/x.pdf');
        $storage->method('resolveProxyUrl')->willReturn('https://api.test/u');
        $this->repoMock->method('addAttachment')->willReturn(80);

        $result = $service->addAdminAttachment(1, 99, $this->makeUploadedFile(self::PDF_BYTES, 'x.pdf'));

        $this->assertSame(80, $result['id']);
    }
}
