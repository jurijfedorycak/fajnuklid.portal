<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Integrations\FreshQRClient;

class AttendanceController extends Controller
{
    private FreshQRClient $freshQRClient;

    public function __construct()
    {
        $this->freshQRClient = new FreshQRClient();
    }

    public function index(Request $request): void
    {
        $clientId = $request->getClientId();

        if ($clientId === null) {
            Response::error('Uživatel není přiřazen ke klientovi', 403);
        }

        $icoFilter = $request->query('ico');
        $month = $request->query('month');

        // FreshQR integration is Phase 2 - return placeholder
        $data = $this->freshQRClient->getAttendanceSummary(
            $icoFilter ?? '',
            $month
        );

        Response::success($data);
    }
}
