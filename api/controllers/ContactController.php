<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\StaffContactRepository;

class ContactController extends Controller
{
    private StaffContactRepository $staffContactRepo;
    private ClientRepository $clientRepo;

    public function __construct()
    {
        $this->staffContactRepo = new StaffContactRepository();
        $this->clientRepo = new ClientRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $client = $this->clientRepo->findByUserId((int) $user['id']);

        // Get all Fajnuklid staff contacts
        $staffContacts = $this->staffContactRepo->findAll();

        $contacts = array_map(function ($c) {
            return [
                'id' => $c['id'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'email' => $c['email'],
            ];
        }, $staffContacts);

        // Company info for Fajnuklid (static for now, verified against ARES)
        $companies = [
            [
                'name' => 'FAJN ÚKLID PRAHA s.r.o.',
                'ico' => '08999457',
                'dic' => 'CZ08999457',
                'address' => 'Bellušova 1854/24, 155 00 Praha 5',
                'registration' => 'Zapsaná v obchodním rejstříku vedeném Městským soudem v Praze, oddíl C, vložka 328945',
            ],
            [
                'name' => 'Fajn Facility Management s.r.o.',
                'ico' => '21328331',
                'dic' => null,
                'address' => 'Bellušova 1854/24, 155 00 Praha 5',
                'registration' => 'Zapsaná v obchodním rejstříku vedeném Městským soudem v Praze, oddíl C, vložka 400210',
            ],
        ];

        // Visiting office (static for now)
        $office = [
            'addressLine1' => 'Karlovo náměstí 313/8',
            'addressLine2' => '120 00 Praha 2, 4. patro',
            'note' => 'Schůzky a návštěvy po předchozí domluvě.',
        ];

        Response::success([
            'contacts' => $contacts,
            'companies' => $companies,
            'office' => $office,
            'whatsappGroupUrl' => $client['whatsapp_group_url'] ?? null,
        ]);
    }
}
