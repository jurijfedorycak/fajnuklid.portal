<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\LocationRepository;

class AttendanceController extends Controller
{
    private LocationRepository $locationRepo;

    public function __construct()
    {
        $this->locationRepo = new LocationRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = $user['id'];

        // Get user's locations
        $locations = $this->locationRepo->findByUserId($userId);
        $locationIds = array_column($locations, 'id');

        // Determine if FreshQR is active (for now, assume active if user has locations)
        $freshqrActive = !empty($locationIds);

        // Get year/month from query params or use current
        $year = (int) ($request->query('year') ?? date('Y'));
        $month = (int) ($request->query('month') ?? date('m'));

        // TODO: Fetch cleaning visits from external API
        // For now, return empty array - will be populated from external API
        $cleaningDays = [];

        Response::success([
            'freshqrActive' => $freshqrActive,
            'cleaningDays' => $cleaningDays,
            'year' => $year,
            'month' => $month,
            'locations' => array_map(function ($l) {
                return [
                    'id' => $l['id'],
                    'name' => $l['name'],
                    'company_name' => $l['company_name'] ?? null,
                ];
            }, $locations),
        ]);
    }
}
