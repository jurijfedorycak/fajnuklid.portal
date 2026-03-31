<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Helpers\JwtHelper;
use App\Helpers\PasswordHelper;
use App\Repositories\UserRepository;

class AuthController extends Controller
{
    private UserRepository $userRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    public function login(Request $request): void
    {
        $data = $this->validate($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:1'
        ]);

        $user = $this->userRepository->findByEmail($data['email']);

        if (!$user) {
            throw new AuthException('Neplatné přihlašovací údaje');
        }

        if (!PasswordHelper::verify($data['password'], $user['password_hash'])) {
            throw new AuthException('Neplatné přihlašovací údaje');
        }

        if (!$user['portal_enabled']) {
            throw new AuthException('Přístup do portálu je zakázán');
        }

        // Update last login timestamp
        $this->userRepository->updateLastLogin($user['id']);

        // Check if user is admin
        $adminEmails = Config::getArray('ADMIN_EMAILS');
        $isAdmin = in_array($user['email'], $adminEmails, true);

        // Generate JWT token
        $token = JwtHelper::createForUser($user['id'], $user['email']);

        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'client_id' => $user['client_id'],
                'is_admin' => $isAdmin
            ]
        ], 'Přihlášení bylo úspěšné');
    }

    public function logout(Request $request): void
    {
        // JWT tokens are stateless, so logout is handled client-side
        // by removing the token. This endpoint exists for API consistency
        // and potential future token blacklisting.
        Response::success(null, 'Odhlášení bylo úspěšné');
    }

    public function me(Request $request): void
    {
        $user = $request->getUser();

        Response::success([
            'id' => $user['id'],
            'email' => $user['email'],
            'client_id' => $user['client_id'],
            'is_admin' => $user['is_admin'] ?? false
        ]);
    }
}
