<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Services\DemoAttendanceService;
use App\Services\FreshQRService;

class AttendanceController extends Controller
{
    private FreshQRService $freshqr;
    private ClientRepository $clientRepo;

    public function __construct()
    {
        $this->freshqr = new FreshQRService();
        $this->clientRepo = new ClientRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];

        $year = self::clampYear((int) ($request->query('year') ?? date('Y')));
        $month = self::clampMonth((int) ($request->query('month') ?? date('m')));

        // Demo clients see a synthetic schedule instead of FreshQR data — but the
        // response shape stays identical so the FE renders without a demo branch.
        // freshqrActive must stay true: the FE hides the calendar in favour of
        // onboarding UI when it's false, which would defeat the demo.
        $client = $this->clientRepo->findByUserId($userId);
        if ($client !== null && (bool) $client['is_demo']) {
            $cleaningDays = DemoAttendanceService::buildCleaningDays(
                $year,
                $month,
                new \DateTimeImmutable('today')
            );
            Response::success([
                'freshqrActive' => true,
                'cleaningDays' => $cleaningDays,
                'error' => null,
                'year' => $year,
                'month' => $month,
            ]);
            return;
        }

        $result = $this->freshqr->getCleaningDaysForUser($userId, $year, $month);

        Response::success([
            'freshqrActive' => $result['active'],
            'cleaningDays' => $result['cleaningDays'],
            'error' => $result['error'] ?? null,
            'year' => $year,
            'month' => $month,
        ]);
    }

    private static function clampYear(int $year): int
    {
        $current = (int) date('Y');
        // Fajnuklid rolled out FreshQR in 2023; values outside this window are
        // either bogus query params or bookmarked-from-future calendar views.
        if ($year < 2023 || $year > $current + 1) {
            return $current;
        }
        return $year;
    }

    private static function clampMonth(int $month): int
    {
        if ($month < 1 || $month > 12) {
            return (int) date('m');
        }
        return $month;
    }
}
