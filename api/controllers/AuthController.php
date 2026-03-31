<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\UserService;

class AuthController extends Controller
{
    private AuthService $authService;
    private UserService $userService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->userService = new UserService();
    }

    public function login(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'email' => 'required|email',
            'password' => 'required|min:1'
        ]);

        $result = $this->authService->login($data['email'], $data['password']);

        Response::success($result, 'Přihlášení úspěšné');
    }

    public function me(Request $request): void
    {
        $userId = $request->getUserId();

        $user = $this->userService->getUserWithIcos($userId);

        if (!$user) {
            Response::error('Uživatel nenalezen', 404);
        }

        $user['is_admin'] = $request->isAdmin();

        Response::success($user);
    }

    public function logout(Request $request): void
    {
        // JWT tokens are stateless, so logout is handled client-side
        // This endpoint exists for consistency and future refresh token support
        Response::success(null, 'Odhlášení úspěšné');
    }

    public function forgotPassword(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'email' => 'required|email'
        ]);

        $this->authService->forgotPassword($data['email']);

        // Always return success to prevent email enumeration
        Response::success(null, 'Pokud účet existuje, obdržíte e-mail s instrukcemi pro reset hesla');
    }

    public function resetPassword(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'token' => 'required|string',
            'password' => 'required|min:8',
            'password_confirmation' => 'required'
        ]);

        if ($data['password'] !== $request->input('password_confirmation')) {
            Response::error('Hesla se neshodují', 422, [
                'password_confirmation' => ['Hesla se neshodují']
            ]);
        }

        $this->authService->resetPassword($data['token'], $data['password']);

        Response::success(null, 'Heslo bylo úspěšně změněno');
    }

    public function changePassword(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8',
            'new_password_confirmation' => 'required'
        ]);

        if ($data['new_password'] !== $request->input('new_password_confirmation')) {
            Response::error('Nová hesla se neshodují', 422, [
                'new_password_confirmation' => ['Nová hesla se neshodují']
            ]);
        }

        $this->authService->changePassword(
            $request->getUserId(),
            $data['current_password'],
            $data['new_password']
        );

        Response::success(null, 'Heslo bylo úspěšně změněno');
    }
}
