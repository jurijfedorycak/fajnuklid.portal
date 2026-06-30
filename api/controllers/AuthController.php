<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Helpers\JwtHelper;
use App\Helpers\PasswordHelper;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\UserRepository;
use App\Services\FreshQRService;

class AuthController extends Controller
{
    private UserRepository $userRepository;
    private ClientRepository $clientRepository;
    private CompanyRepository $companyRepository;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->clientRepository = new ClientRepository();
        $this->companyRepository = new CompanyRepository();
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

        $isAdmin = (bool) ($user['is_admin'] ?? false);

        // Generate JWT token
        $token = JwtHelper::createForUser($user['id'], $user['email']);

        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'is_admin' => $isAdmin,
                'attendance_enabled' => $this->attendanceEnabledForUser((int) $user['id'])
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
            'is_admin' => $user['is_admin'] ?? false,
            'attendance_enabled' => $this->attendanceEnabledForUser((int) $user['id'])
        ]);
    }

    /**
     * Whether the portal should expose the attendance surfaces (Přehled
     * docházky card + Docházka tab) for this user. Enabled when the client is
     * a demo account (always showcases the calendar) or at least one of their
     * IČOs has FreshQR switched on. Clients with no activated QR system get
     * those features hidden so the portal serves only invoices, requests,
     * contracts and personnel.
     */
    private function attendanceEnabledForUser(int $userId): bool
    {
        $client = $this->clientRepository->findByUserId($userId);
        if ($client !== null && (bool) $client['is_demo']) {
            return true;
        }

        $companies = $this->companyRepository->findByUserId($userId);

        return FreshQRService::isAttendanceEnabledForCompanies($companies);
    }
}
