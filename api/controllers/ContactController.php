<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StaffContactRepository;
use App\Services\R2StorageService;

class ContactController extends Controller
{
    private StaffContactRepository $staffContactRepo;
    private R2StorageService $storage;

    public function __construct()
    {
        $this->staffContactRepo = new StaffContactRepository();
        $this->storage = new R2StorageService();
    }

    public function index(Request $request): void
    {
        // Get all Fajnuklid staff contacts
        $staffContacts = $this->staffContactRepo->findAll();

        $contacts = array_map(function ($c) {
            return [
                'id' => $c['id'],
                'name' => $c['name'],
                'role' => $c['position'],
                'phone' => $c['phone'],
                'email' => $c['email'],
                'photo_url' => $this->storage->resolveProxyUrl($c['photo_url'] ?? null),
            ];
        }, $staffContacts);

        // Company info for Fajnuklid (static for now)
        $companies = [
            [
                'name' => 'FAJN ÚKLID s.r.o.',
                'ico' => '12345678',
                'dic' => 'CZ12345678',
                'address' => 'Příkladná 123, 150 00 Praha 5',
                'registration' => 'Zapsán v OR vedeném Městským soudem v Praze, oddíl C, vložka XXXXX',
            ],
        ];

        Response::success([
            'contacts' => $contacts,
            'companies' => $companies,
        ]);
    }
}
