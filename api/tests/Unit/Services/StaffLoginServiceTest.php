<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\StaffContactRepository;
use App\Repositories\UserRepository;
use App\Services\StaffLoginService;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class StaffLoginServiceTest extends TestCase
{
    private StaffLoginService $service;
    private MockObject&UserRepository $userRepo;
    private MockObject&StaffContactRepository $staffRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepo = $this->createMock(UserRepository::class);
        $this->staffRepo = $this->createMock(StaffContactRepository::class);

        $this->service = new StaffLoginService($this->userRepo, $this->staffRepo);
    }

    private function staffRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Test Staff',
            'position' => null,
            'phone' => null,
            'email' => 'staff@fajnuklid.cz',
            'user_id' => null,
            'photo_url' => null,
            'sort_order' => 0,
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
            'deleted_at' => null,
            'login_portal_enabled' => null,
        ], $overrides);
    }

    // setPasswordForStaff

    public function testSetPasswordForStaffThrowsWhenStaffMissing(): void
    {
        $this->staffRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->setPasswordForStaff(1, 'Strong1234');
    }

    public function testSetPasswordForStaffThrowsWhenEmailEmpty(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['email' => '']));

        $this->expectException(ValidationException::class);
        $this->service->setPasswordForStaff(1, 'Strong1234');
    }

    public function testSetPasswordForStaffCreatesNewLoginWhenNoneLinked(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => null]));
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->expects($this->once())
            ->method('create')
            ->willReturn(42);
        $this->userRepo->expects($this->once())
            ->method('update')
            ->with(42, ['is_admin' => true]);
        $this->staffRepo->expects($this->once())
            ->method('setUserId')
            ->with(1, 42);

        $result = $this->service->setPasswordForStaff(1, 'Strong1234');

        $this->assertSame(42, $result['user_id']);
        $this->assertSame('staff@fajnuklid.cz', $result['login_email']);
        $this->assertTrue($result['login_enabled']);
    }

    public function testSetPasswordForStaffThrowsWhenEmailCollides(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => null]));
        $this->userRepo->method('existsByEmail')->willReturn(true);
        $this->userRepo->expects($this->never())->method('create');

        $this->expectException(ValidationException::class);
        $this->service->setPasswordForStaff(1, 'Strong1234');
    }

    public function testSetPasswordForStaffRotatesPasswordWhenLoginExists(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 99]));
        $this->userRepo->expects($this->once())
            ->method('updatePassword')
            ->with(99, $this->isType('string'));
        $this->userRepo->expects($this->once())
            ->method('update')
            ->with(99, ['portal_enabled' => true, 'is_admin' => true]);
        $this->staffRepo->expects($this->never())->method('setUserId');

        $result = $this->service->setPasswordForStaff(1, 'Strong1234');

        $this->assertSame(99, $result['user_id']);
        $this->assertTrue($result['login_enabled']);
    }

    public function testSetPasswordForStaffMapsUniqueViolationToValidationError(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => null]));
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $integrityException = new PDOException('Duplicate entry');
        // PDOException uses string codes — '23000' is the SQLSTATE for integrity violations.
        $reflectionCode = new \ReflectionProperty(\Exception::class, 'code');
        $reflectionCode->setAccessible(true);
        $reflectionCode->setValue($integrityException, '23000');

        $this->userRepo->method('create')->willThrowException($integrityException);

        $this->expectException(ValidationException::class);
        $this->service->setPasswordForStaff(1, 'Strong1234');
    }

    // revokeLogin

    public function testRevokeLoginThrowsWhenStaffMissing(): void
    {
        $this->staffRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->revokeLogin(1);
    }

    public function testRevokeLoginIsNoopWhenNoLink(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => null]));
        $this->userRepo->expects($this->never())->method('update');

        $this->service->revokeLogin(1);
    }

    public function testRevokeLoginDisablesLogin(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 7]));
        $this->userRepo->expects($this->once())
            ->method('update')
            ->with(7, ['portal_enabled' => false, 'is_admin' => false]);

        $this->service->revokeLogin(1);
    }

    // syncEmailIfChanged

    public function testSyncEmailIsNoopWhenNoLink(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => null]));
        $this->userRepo->expects($this->never())->method('findById');
        $this->userRepo->expects($this->never())->method('update');

        $this->service->syncEmailIfChanged(1, 'new@fajnuklid.cz');
    }

    public function testSyncEmailThrowsWhenClearedOnLinkedStaff(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 7]));

        $this->expectException(ValidationException::class);
        $this->service->syncEmailIfChanged(1, '');
    }

    public function testSyncEmailIsNoopWhenUnchanged(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 7]));
        $this->userRepo->method('findById')->willReturn(['id' => 7, 'email' => 'staff@fajnuklid.cz']);
        $this->userRepo->expects($this->never())->method('update');

        $this->service->syncEmailIfChanged(1, 'STAFF@fajnuklid.cz');
    }

    public function testSyncEmailThrowsOnCollision(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 7]));
        $this->userRepo->method('findById')->willReturn(['id' => 7, 'email' => 'old@fajnuklid.cz']);
        $this->userRepo->method('existsByEmail')->willReturn(true);
        $this->userRepo->expects($this->never())->method('update');

        $this->expectException(ValidationException::class);
        $this->service->syncEmailIfChanged(1, 'taken@fajnuklid.cz');
    }

    public function testSyncEmailUpdatesLoginWhenCollisionFree(): void
    {
        $this->staffRepo->method('findById')->willReturn($this->staffRow(['user_id' => 7]));
        $this->userRepo->method('findById')->willReturn(['id' => 7, 'email' => 'old@fajnuklid.cz']);
        $this->userRepo->method('existsByEmail')->willReturn(false);
        $this->userRepo->expects($this->once())
            ->method('update')
            ->with(7, ['email' => 'fresh@fajnuklid.cz']);

        $this->service->syncEmailIfChanged(1, 'fresh@fajnuklid.cz');
    }

    // disableLoginForUser

    public function testDisableLoginForUserIsNoopWhenNull(): void
    {
        $this->userRepo->expects($this->never())->method('update');

        $this->service->disableLoginForUser(null);
    }

    public function testDisableLoginForUserDisablesLogin(): void
    {
        $this->userRepo->expects($this->once())
            ->method('update')
            ->with(7, ['portal_enabled' => false, 'is_admin' => false]);

        $this->service->disableLoginForUser(7);
    }
}
