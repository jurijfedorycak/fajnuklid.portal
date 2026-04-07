<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\UserSettingsRepository;
use PDO;
use RuntimeException;
use Tests\DatabaseTestCase;

class UserSettingsRepositoryTest extends DatabaseTestCase
{
    private UserSettingsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(UserSettingsRepository::class);
    }

    // findById tests

    public function testFindByIdReturnsSettingsWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'user_id' => 1,
            'notification_email' => 1,
            'notification_invoice' => 1,
            'notification_attendance' => 0,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
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

    // findByUserId tests

    public function testFindByUserIdReturnsSettingsWhenFound(): void
    {
        $expected = [
            'id' => 1,
            'user_id' => 1,
            'notification_email' => 1,
        ];

        $this->setupFetchMock($expected);

        $result = $this->repository->findByUserId(1);

        $this->assertEquals($expected, $result);
    }

    public function testFindByUserIdReturnsNullWhenNotFound(): void
    {
        $this->setupFetchMock(false);

        $result = $this->repository->findByUserId(999);

        $this->assertNull($result);
    }

    // findOrCreate tests

    public function testFindOrCreateReturnsExistingSettings(): void
    {
        $existingSettings = [
            'id' => 1,
            'user_id' => 1,
            'notification_email' => 1,
            'notification_invoice' => 1,
            'notification_attendance' => 1,
        ];

        $this->setupFetchMock($existingSettings);

        $result = $this->repository->findOrCreate(1);

        $this->assertEquals($existingSettings, $result);
    }

    public function testFindOrCreateCreatesNewSettingsWithDefaults(): void
    {
        $newSettings = [
            'id' => 1,
            'user_id' => 1,
            'notification_email' => 1,
            'notification_invoice' => 1,
            'notification_attendance' => 1,
        ];

        // First call returns null (not found), then insert, then find returns new settings
        $stmtFind = $this->createStatementMock();
        $stmtFind->method('fetch')
            ->willReturnOnConsecutiveCalls(false, $newSettings);
        $stmtFind->method('execute')->willReturn(true);

        $stmtInsert = $this->createStatementMock();
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtFind, $stmtInsert, $stmtFind);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $result = $this->repository->findOrCreate(1);

        $this->assertEquals($newSettings, $result);
    }

    public function testFindOrCreateThrowsExceptionOnFailure(): void
    {
        // First call returns null, insert succeeds, but second find also returns null
        $stmtFind = $this->createStatementMock();
        $stmtFind->method('fetch')->willReturn(false);
        $stmtFind->method('execute')->willReturn(true);

        $stmtInsert = $this->createStatementMock();
        $stmtInsert->method('execute')->willReturn(true);

        $this->pdoMock->method('prepare')
            ->willReturnOnConsecutiveCalls($stmtFind, $stmtInsert, $stmtFind);
        $this->pdoMock->method('lastInsertId')->willReturn('1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create user settings for user ID: 1');

        $this->repository->findOrCreate(1);
    }

    // create tests

    public function testCreateReturnsNewId(): void
    {
        $this->setupInsertMock(1);

        $result = $this->repository->create([
            'user_id' => 1,
        ]);

        $this->assertEquals(1, $result);
    }

    public function testCreateWithAllNotificationFlags(): void
    {
        $this->setupInsertMock(2);

        $result = $this->repository->create([
            'user_id' => 1,
            'notification_email' => false,
            'notification_invoice' => true,
            'notification_attendance' => false,
        ]);

        $this->assertEquals(2, $result);
    }

    // update tests

    public function testUpdateReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->update(1, ['notification_email' => false]);

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

        $result = $this->repository->update(999, ['notification_email' => true]);

        $this->assertFalse($result);
    }

    // updateByUserId tests

    public function testUpdateByUserIdReturnsTrueWhenModified(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->updateByUserId(1, ['notification_invoice' => false]);

        $this->assertTrue($result);
    }

    public function testUpdateByUserIdReturnsFalseWhenNoFieldsProvided(): void
    {
        $result = $this->repository->updateByUserId(1, []);

        $this->assertFalse($result);
    }

    public function testUpdateByUserIdReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->updateByUserId(999, ['notification_email' => true]);

        $this->assertFalse($result);
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

    // deleteByUserId tests

    public function testDeleteByUserIdReturnsTrueWhenDeleted(): void
    {
        $this->setupRowCountMock(1);

        $result = $this->repository->deleteByUserId(1);

        $this->assertTrue($result);
    }

    public function testDeleteByUserIdReturnsFalseWhenNotFound(): void
    {
        $this->setupRowCountMock(0);

        $result = $this->repository->deleteByUserId(999);

        $this->assertFalse($result);
    }

    // findUsersWithNotificationEnabled tests

    public function testFindUsersWithNotificationEnabledByEmail(): void
    {
        $expected = [
            ['id' => 1, 'user_id' => 1, 'email' => 'user1@example.com'],
            ['id' => 2, 'user_id' => 2, 'email' => 'user2@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findUsersWithNotificationEnabled('notification_email');

        $this->assertEquals($expected, $result);
    }

    public function testFindUsersWithNotificationEnabledByInvoice(): void
    {
        $expected = [
            ['id' => 1, 'user_id' => 1, 'email' => 'user1@example.com'],
        ];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findUsersWithNotificationEnabled('notification_invoice');

        $this->assertEquals($expected, $result);
    }

    public function testFindUsersWithNotificationEnabledByAttendance(): void
    {
        $expected = [];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findUsersWithNotificationEnabled('notification_attendance');

        $this->assertEquals($expected, $result);
    }

    public function testFindUsersWithNotificationEnabledReturnsEmptyForInvalidType(): void
    {
        $result = $this->repository->findUsersWithNotificationEnabled('invalid_type');

        $this->assertEquals([], $result);
    }

    public function testFindUsersWithNotificationEnabledExcludesDisabledPortal(): void
    {
        $expected = [];

        $this->stmtMock->method('fetchAll')->willReturn($expected);
        $this->stmtMock->method('execute')->willReturn(true);
        $this->pdoMock->method('prepare')->willReturn($this->stmtMock);

        $result = $this->repository->findUsersWithNotificationEnabled('notification_email');

        $this->assertEquals($expected, $result);
    }
}
