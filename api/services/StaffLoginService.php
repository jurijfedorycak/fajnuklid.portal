<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Helpers\PasswordHelper;
use App\Repositories\StaffContactRepository;
use App\Repositories\UserRepository;
use PDOException;

class StaffLoginService
{
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    private UserRepository $userRepo;
    private StaffContactRepository $staffContactRepo;

    public function __construct(
        ?UserRepository $userRepo = null,
        ?StaffContactRepository $staffContactRepo = null
    ) {
        $this->userRepo = $userRepo ?? new UserRepository();
        $this->staffContactRepo = $staffContactRepo ?? new StaffContactRepository();
    }

    public function setPasswordForStaff(int $staffId, string $newPassword): array
    {
        $staff = $this->staffContactRepo->findById($staffId);
        if (!$staff) {
            throw new NotFoundException('Kontaktní osoba nebyla nalezena.');
        }

        $email = isset($staff['email']) ? trim((string) $staff['email']) : '';
        if ($email === '') {
            throw new ValidationException('Pro nastavení hesla je vyžadován e-mail kontaktu.', [
                'email' => 'Pro nastavení hesla je vyžadován e-mail kontaktu.',
            ]);
        }

        $passwordHash = PasswordHelper::hash($newPassword);
        $userId = $staff['user_id'] !== null ? (int) $staff['user_id'] : null;

        if ($userId !== null) {
            $this->userRepo->updatePassword($userId, $passwordHash);
            $this->userRepo->update($userId, [
                'portal_enabled' => true,
                'is_admin' => true,
            ]);

            return [
                'user_id' => $userId,
                'login_email' => $email,
                'login_enabled' => true,
            ];
        }

        if ($this->userRepo->existsByEmail($email)) {
            throw new ValidationException('Tento e-mail už používá jiný účet — zvolte jiný.', [
                'email' => 'Tento e-mail už používá jiný účet — zvolte jiný.',
            ]);
        }

        try {
            $newUserId = $this->userRepo->create([
                'email' => $email,
                'password_hash' => $passwordHash,
                'portal_enabled' => true,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
                throw new ValidationException('Tento e-mail už používá jiný účet — zvolte jiný.', [
                    'email' => 'Tento e-mail už používá jiný účet — zvolte jiný.',
                ]);
            }
            throw $e;
        }

        $this->userRepo->update($newUserId, ['is_admin' => true]);
        $this->staffContactRepo->setUserId($staffId, $newUserId);

        return [
            'user_id' => $newUserId,
            'login_email' => $email,
            'login_enabled' => true,
        ];
    }

    public function revokeLogin(int $staffId): void
    {
        $staff = $this->staffContactRepo->findById($staffId);
        if (!$staff) {
            throw new NotFoundException('Kontaktní osoba nebyla nalezena.');
        }

        if ($staff['user_id'] === null) {
            return;
        }

        $this->userRepo->update((int) $staff['user_id'], [
            'portal_enabled' => false,
            'is_admin' => false,
        ]);
    }

    public function syncEmailIfChanged(int $staffId, ?string $newEmail): void
    {
        $staff = $this->staffContactRepo->findById($staffId);
        if (!$staff || $staff['user_id'] === null) {
            return;
        }

        $userId = (int) $staff['user_id'];
        $trimmed = $newEmail !== null ? trim($newEmail) : '';

        if ($trimmed === '') {
            throw new ValidationException(
                'Nelze odebrat e-mail u člena s aktivním přístupem. Nejprve zrušte přístup.',
                ['email' => 'Nelze odebrat e-mail u člena s aktivním přístupem. Nejprve zrušte přístup.']
            );
        }

        $currentLogin = $this->userRepo->findById($userId);
        if (!$currentLogin) {
            return;
        }

        if (strcasecmp((string) $currentLogin['email'], $trimmed) === 0) {
            return;
        }

        if ($this->userRepo->existsByEmail($trimmed, $userId)) {
            throw new ValidationException('Tento e-mail už používá jiný účet — zvolte jiný.', [
                'email' => 'Tento e-mail už používá jiný účet — zvolte jiný.',
            ]);
        }

        $this->userRepo->update($userId, ['email' => $trimmed]);
    }

    public function disableLoginForUser(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $this->userRepo->update($userId, [
            'portal_enabled' => false,
            'is_admin' => false,
        ]);
    }
}
