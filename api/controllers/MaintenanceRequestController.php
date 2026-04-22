<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ApiException;
use App\Repositories\CompanyRepository;
use App\Services\MaintenanceRequestService;

class MaintenanceRequestController extends Controller
{
    private MaintenanceRequestService $service;
    private CompanyRepository $companyRepo;

    public function __construct()
    {
        $this->service = new MaintenanceRequestService();
        $this->companyRepo = new CompanyRepository();
    }

    /**
     * GET /maintenance-requests/form-options
     * Returns the user's protistrany (companies/IČO) used to bind a new request.
     */
    public function formOptions(Request $request): void
    {
        $userId = (int) $request->getUserId();
        $companies = $this->companyRepo->findByUserId($userId);

        $list = array_map(function ($c) {
            return [
                'id' => (int) $c['id'],
                'ico' => $c['registration_number'] ?? null,
                'name' => $c['name'] ?? null,
            ];
        }, $companies);

        Response::success(['companies' => $list]);
    }

    /**
     * GET /maintenance-requests?status=&limit=
     */
    public function index(Request $request): void
    {
        // Users without a client assignment legitimately have zero requests. Return an
        // empty list instead of failing — the dashboard widget calls this on every load,
        // and throwing would force the FE to handle an error for a genuinely valid state.
        $userId = (int) $request->getUserId();
        $clientId = $this->service->resolveClientIdForUser($userId);
        if ($clientId === null) {
            Response::success([]);
            return;
        }

        $status = $request->query('status');
        if ($status === 'all') {
            $status = null;
        }
        $limit = $request->query('limit');
        $limitInt = ($limit !== null && $limit !== '') ? (int) $limit : null;

        $requests = $this->service->listForClient($clientId, $status, $limitInt);

        Response::success($requests);
    }

    /**
     * GET /maintenance-requests/calendar?year=&month=
     */
    public function calendar(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $year = (int) ($request->query('year') ?: date('Y'));
        $month = (int) ($request->query('month') ?: date('n'));

        $data = $this->service->calendarForClient($clientId, $year, $month);
        Response::success($data);
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

        Response::created($data, 'Požadavek byl vytvořen');
    }

    /**
     * POST /maintenance-requests/{id}/confirm
     */
    public function confirm(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();
        $userName = $this->resolveUserDisplayName($userId);
        $id = (int) $request->param('id');

        $data = $this->service->clientConfirm($id, $clientId, $userId, $userName);
        Response::success($data, 'Požadavek byl potvrzen');
    }

    /**
     * POST /maintenance-requests/{id}/reject
     */
    public function reject(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();
        $userName = $this->resolveUserDisplayName($userId);
        $id = (int) $request->param('id');
        $body = $request->getBody();
        $comment = (string) ($body['comment'] ?? '');

        $data = $this->service->clientReject($id, $clientId, $userId, $userName, $comment);
        Response::success($data, 'Požadavek byl vrácen k řešení');
    }

    /**
     * POST /maintenance-requests/{id}/cancel
     */
    public function cancel(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();
        $userName = $this->resolveUserDisplayName($userId);
        $id = (int) $request->param('id');

        $this->service->clientCancel($id, $clientId, $userId, $userName);
        Response::success(['id' => $id], 'Požadavek byl zrušen');
    }

    /**
     * POST /maintenance-requests/{id}/attachments
     */
    public function uploadAttachment(Request $request): void
    {
        $clientId = $this->resolveClientId($request);
        $userId = (int) $request->getUserId();
        $id = (int) $request->param('id');

        if (!isset($_FILES['file'])) {
            Response::error('Soubor nebyl nahrán', 422);
            return;
        }

        $data = $this->service->addClientAttachment($id, $clientId, $userId, $_FILES['file']);
        Response::created($data, 'Příloha byla nahrána');
    }

    /**
     * Used by methods that always need a client context (show/create/confirm/…).
     * Returns HTTP 403 rather than 401 when missing — the user IS authenticated,
     * they just lack a client assignment. The FE axios interceptor treats 401
     * as "session dead" and wipes the token, so misusing 401 here was causing
     * a redirect loop after login.
     */
    private function resolveClientId(Request $request): int
    {
        $userId = (int) $request->getUserId();
        $clientId = $this->service->resolveClientIdForUser($userId);

        if ($clientId === null) {
            throw new ApiException('Váš účet není přiřazen k žádnému klientovi.', 403);
        }

        return $clientId;
    }

    private function resolveUserDisplayName(int $userId): string
    {
        try {
            $repo = new \App\Repositories\UserRepository();
            $user = $repo->findById($userId);
            if ($user) {
                return $user['display_name'] ?? $user['email'] ?? 'Klient';
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 'Klient';
    }
}
