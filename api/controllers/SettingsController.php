<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\UserSettingsRepository;
use App\Repositories\UserRepository;
use App\Helpers\PasswordHelper;
use App\Exceptions\AuthException;
use App\Exceptions\ValidationException;

class SettingsController extends Controller
{
    private CompanyRepository $companyRepo;
    private UserSettingsRepository $userSettingsRepo;
    private UserRepository $userRepo;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->userSettingsRepo = new UserSettingsRepository();
        $this->userRepo = new UserRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's companies (IČOs)
        $companies = $this->companyRepo->findByUserId($userId);

        // Get user settings
        $settings = $this->userSettingsRepo->findOrCreate($userId);

        // Build IČOs list
        $icos = array_map(function ($c) {
            return [
                'ico' => $c['registration_number'],
                'name' => $c['name'],
                'address' => $c['address'],
            ];
        }, $companies);

        // Build current user info
        $currentUser = [
            'id' => $user['id'],
            'email' => $user['email'],
            'display_name' => !empty($companies) ? $companies[0]['name'] : $user['email'],
            'client_id' => $user['client_id'] ?? null,
            'icos' => $icos,
        ];

        Response::success([
            'current_user' => $currentUser,
            'settings' => [
                'notification_email' => (bool) $settings['notification_email'],
                'notification_invoice' => (bool) $settings['notification_invoice'],
                'notification_attendance' => (bool) $settings['notification_attendance'],
            ],
        ]);
    }

    public function update(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        $data = $request->all();

        // Update notification settings if provided
        if (isset($data['settings'])) {
            $settingsData = [];
            if (isset($data['settings']['notification_email'])) {
                $settingsData['notification_email'] = (bool) $data['settings']['notification_email'];
            }
            if (isset($data['settings']['notification_invoice'])) {
                $settingsData['notification_invoice'] = (bool) $data['settings']['notification_invoice'];
            }
            if (isset($data['settings']['notification_attendance'])) {
                $settingsData['notification_attendance'] = (bool) $data['settings']['notification_attendance'];
            }

            if (!empty($settingsData)) {
                $settings = $this->userSettingsRepo->findOrCreate($userId);
                $this->userSettingsRepo->update((int) $settings['id'], $settingsData);
            }
        }

        Response::success(null, 'Nastavení bylo uloženo');
    }

    public function changePassword(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        $data = $this->validate($request->all(), [
            'current_password' => 'required|string|min:1',
            'new_password' => 'required|string|min:8',
            'confirm_password' => 'required|string|min:8',
        ]);

        // Verify current password
        $fullUser = $this->userRepo->findById($userId);
        if (!$fullUser || !PasswordHelper::verify($data['current_password'], $fullUser['password_hash'])) {
            throw new AuthException('Stávající heslo není správné');
        }

        // Verify passwords match
        if ($data['new_password'] !== $data['confirm_password']) {
            throw new ValidationException('Nové heslo a potvrzení se neshodují', [
                'confirm_password' => ['Hesla se neshodují'],
            ]);
        }

        // Update password
        $newHash = PasswordHelper::hash($data['new_password']);
        $this->userRepo->updatePassword($userId, $newHash);

        Response::success(null, 'Heslo bylo změněno');
    }
}
