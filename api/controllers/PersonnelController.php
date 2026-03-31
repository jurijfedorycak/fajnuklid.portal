<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\PersonnelService;

class PersonnelController extends Controller
{
    private PersonnelService $personnelService;

    public function __construct()
    {
        $this->personnelService = new PersonnelService();
    }

    public function index(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $icoFilter = $request->query('ico');

        $personnel = $this->personnelService->getPersonnelForClient($clientId, $icoFilter);

        Response::success($personnel);
    }
}
