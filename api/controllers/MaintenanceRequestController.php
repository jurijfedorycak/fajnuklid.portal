<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Repositories\LocationRepository;
use App\Services\MaintenanceRequestService;

class MaintenanceRequestController extends Controller
{
    private MaintenanceRequestService $service;
    private LocationRepository $locationRepo;

    public function __construct()
    {
        $this->service = new MaintenanceRequestService();
        $this->locationRepo = new LocationRepository();
    }

    /**
     * GET /maintenance-requests/form-options
     * Returns options needed to render the new-request form: user's offices.
     */
    public function formOptions(Request $request): void
    {
        $userId = (int) $request->getUserId();
        $locations = $this->locationRepo->findByUserId($userId);

        $offices = array_map(function ($l) {
            return [
                'id' => (int) $l['id'],
                'companyId' => (int) $l['company_id'],
                'name' => $l['name'],
                'companyName' => $l['company_name'] ?? null,
            ];
        }, $locations);

        Response::success(['offices' => $offices]);
    }

    /**
     * GET /maintenance-requests?status=
     */
    public function index(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $status = $request->query('status');
        if ($status === 'all') {
            $status = null;
        }

        $requests = $this->service->listForClient($clientId, $status);

        Response::success($requests);
    }

    /**
     * GET /maintenance-requests/{id}
     */
    public function show(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $id = (int) $request->param('id');

        $data = $this->service->getForClient($id, $clientId);
        Response::success($data);
    }

    /**
     * POST /maintenance-requests
     */
    public function create(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();

        $data = $this->service->create($clientId, $userId, $request->getBody());

        Response::created($data, 'Žádost byla vytvořena');
    }

    /**
     * POST /maintenance-requests/{id}/confirm
     */
    public function confirm(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();
        $userName = 'Klient';
        $id = (int) $request->param('id');

        $data = $this->service->clientConfirm($id, $clientId, $userId, $userName);
        Response::success($data, 'Žádost byla potvrzena');
    }

    private function resolveClientId(Request $request): int
    {
        $userId = (int) $request->getUserId();
        $clientId = $this->service->resolveClientIdForUser($userId);

        if ($clientId === null) {
            throw new AuthException('Váš účet není přiřazen k žádnému klientovi.');
        }

        return $clientId;
    }
}
