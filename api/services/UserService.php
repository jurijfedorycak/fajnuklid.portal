<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use App\Repositories\IcoRepository;

class UserService
{
    private UserRepository $userRepository;
    private IcoRepository $icoRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->icoRepository = new IcoRepository();
    }

    public function getUserWithIcos(int $userId): ?array
    {
        $user = $this->userRepository->findById($userId);

        if (!$user) {
            return null;
        }

        $icos = [];
        if ($user['client_id']) {
            $icos = $this->icoRepository->findByClientId($user['client_id']);
        }

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'client_id' => $user['client_id'],
            'client_name' => $user['client_name'] ?? null,
            'portal_enabled' => (bool) $user['portal_enabled'],
            'icos' => array_map(function ($ico) {
                return [
                    'id' => $ico['id'],
                    'ico' => $ico['ico'],
                    'name' => $ico['name']
                ];
            }, $icos)
        ];
    }

    public function getClientIcos(int $clientId): array
    {
        return $this->icoRepository->findByClientId($clientId);
    }
}
