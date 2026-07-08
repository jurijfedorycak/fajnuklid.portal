<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\AdminController;
use App\Repositories\CompanyRepository;
use App\Repositories\CompanyUserRepository;
use App\Repositories\UserRepository;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Reflection-based coverage for the archive/restore login gate. Archiving a client
 * must revoke portal access on every linked account, and restoring must grant it
 * back — both without hitting the DB, so the disable/enable contract is pinned.
 */
class AdminControllerTest extends TestCase
{
    /**
     * @param array<int, array{0:int,1:array}> $captured out-param collecting userRepo->update calls
     */
    private function invokeSetClientLoginsEnabled(int $clientId, bool $enabled, array &$captured): void
    {
        $companyRepo = $this->createMock(CompanyRepository::class);
        $companyRepo->method('findByClientId')->with($clientId)->willReturn([
            ['id' => 10],
            ['id' => 11],
        ]);

        $companyUserRepo = $this->createMock(CompanyUserRepository::class);
        $companyUserRepo->method('findByCompanyId')->willReturnMap([
            [10, [['user_id' => 100], ['user_id' => 101]]],
            // 101 is linked to both companies — it must only be toggled once.
            [11, [['user_id' => 101], ['user_id' => 102]]],
        ]);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('update')->willReturnCallback(function ($id, $data) use (&$captured) {
            $captured[] = [$id, $data];
            return true;
        });

        $reflection = new ReflectionClass(AdminController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty($controller, 'companyRepo', $companyRepo);
        $this->setPrivateProperty($controller, 'companyUserRepo', $companyUserRepo);
        $this->setPrivateProperty($controller, 'userRepo', $userRepo);

        $method = new ReflectionMethod(AdminController::class, 'setClientLoginsEnabled');
        $method->setAccessible(true);
        $method->invoke($controller, $clientId, $enabled);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    public function testArchiveDisablesEachLinkedLoginExactlyOnce(): void
    {
        $captured = [];
        $this->invokeSetClientLoginsEnabled(5, false, $captured);

        $this->assertCount(3, $captured, 'Each unique login toggled once, duplicates skipped');

        $ids = array_column($captured, 0);
        sort($ids);
        $this->assertSame([100, 101, 102], $ids);

        foreach ($captured as [, $data]) {
            $this->assertArrayHasKey('portal_enabled', $data);
            $this->assertFalse($data['portal_enabled']);
        }
    }

    public function testRestoreReEnablesEachLinkedLogin(): void
    {
        $captured = [];
        $this->invokeSetClientLoginsEnabled(5, true, $captured);

        $this->assertCount(3, $captured);

        foreach ($captured as [, $data]) {
            $this->assertTrue($data['portal_enabled']);
        }
    }
}
