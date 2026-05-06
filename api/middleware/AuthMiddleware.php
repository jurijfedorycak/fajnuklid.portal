<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Exceptions\AuthException;
use App\Helpers\JwtHelper;
use App\Repositories\UserRepository;

class AuthMiddleware
{
    public function handle(Request $request): void
    {
        $token = $request->getBearerToken();

        if (!$token) {
            throw new AuthException('Chybí autorizační token');
        }

        $payload = JwtHelper::decode($token);

        if (!$payload) {
            throw new AuthException('Neplatný nebo expirovaný token');
        }

        $userRepository = new UserRepository();
        $user = $userRepository->findById((int) $payload['sub']);

        if (!$user) {
            throw new AuthException('Uživatel nenalezen');
        }

        if (!$user['portal_enabled']) {
            throw new AuthException('Přístup do portálu je zakázán');
        }

        $user['is_admin'] = (bool) ($user['is_admin'] ?? false);

        $request->setUser($user);
    }
}
