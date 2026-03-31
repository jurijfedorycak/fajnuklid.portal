<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;
use App\Helpers\JwtHelper;
use App\Helpers\PasswordHelper;
use App\Repositories\UserRepository;
use App\Repositories\TokenRepository;

class AuthService
{
    private UserRepository $userRepository;
    private TokenRepository $tokenRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->tokenRepository = new TokenRepository();
    }

    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            throw new AuthException('Neplatné přihlašovací údaje');
        }

        if (!PasswordHelper::verify($password, $user['password_hash'])) {
            throw new AuthException('Neplatné přihlašovací údaje');
        }

        if (!$user['portal_enabled']) {
            throw new AuthException('Přístup do portálu je zakázán');
        }

        // Check if password needs rehash
        if (PasswordHelper::needsRehash($user['password_hash'])) {
            $this->userRepository->updatePassword(
                $user['id'],
                PasswordHelper::hash($password)
            );
        }

        $token = JwtHelper::createForUser($user['id'], $user['email']);

        return [
            'token' => $token,
            'user' => $this->sanitizeUser($user)
        ];
    }

    public function forgotPassword(string $email): bool
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            // Return true even if user not found to prevent email enumeration
            return true;
        }

        $token = PasswordHelper::generateResetToken();

        $this->tokenRepository->createPasswordResetToken($user['id'], $token);

        // Send email (delegated to EmailService)
        $emailService = new EmailService();
        $emailService->sendPasswordResetEmail($email, $token);

        return true;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $tokenData = $this->tokenRepository->findValidToken($token);

        if (!$tokenData) {
            throw new ValidationException('Neplatný nebo expirovaný odkaz pro reset hesla');
        }

        $passwordHash = PasswordHelper::hash($newPassword);

        $this->userRepository->updatePassword($tokenData['user_id'], $passwordHash);
        $this->tokenRepository->markAsUsed($tokenData['id']);

        return true;
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->userRepository->findById($userId);

        if (!$user) {
            throw new AuthException('Uživatel nenalezen');
        }

        if (!PasswordHelper::verify($currentPassword, $user['password_hash'])) {
            throw new ValidationException('Současné heslo není správné', [
                'current_password' => ['Současné heslo není správné']
            ]);
        }

        $passwordHash = PasswordHelper::hash($newPassword);

        return $this->userRepository->updatePassword($userId, $passwordHash);
    }

    public function getUser(int $userId): ?array
    {
        $user = $this->userRepository->findById($userId);

        if (!$user) {
            return null;
        }

        return $this->sanitizeUser($user);
    }

    private function sanitizeUser(array $user): array
    {
        unset($user['password_hash']);

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'client_id' => $user['client_id'],
            'client_name' => $user['client_name'] ?? null,
            'portal_enabled' => (bool) $user['portal_enabled'],
            'created_at' => $user['created_at']
        ];
    }
}
