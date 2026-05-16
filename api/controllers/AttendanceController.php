<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Services\AttendanceSummaryService;
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
                'hourlySummary' => AttendanceSummaryService::buildHourlySummary(
                    DemoAttendanceService::syntheticCompanies(),
                    $cleaningDays
                ),
                'error' => null,
                'year' => $year,
                'month' => $month,
            ]);
            return;
        }

        $result = $this->freshqr->getCleaningDaysForUser($userId, $year, $month);

        // Summary uses the pre-strip data so IČOs without rounding rules can
        // fall back to rawMinutes. Companies are surfaced by the service so we
        // don't re-query them here.
        $hourlySummary = AttendanceSummaryService::buildHourlySummary(
            $result['companies'] ?? [],
            $result['cleaningDays']
        );

        $isAdmin = (bool) ($user['is_admin'] ?? false);
        $cleaningDays = $isAdmin
            ? $result['cleaningDays']
            : self::stripRawTimesWhenRounded($result['cleaningDays']);

        Response::success([
            'freshqrActive' => $result['active'],
            'cleaningDays' => $cleaningDays,
            'hourlySummary' => $hourlySummary,
            'error' => $result['error'] ?? null,
            'year' => $year,
            'month' => $month,
        ]);
    }

    /**
     * Non-admin clients must never see raw scan times for cleanings whose IČO has
     * rounding rules defined — the whole point of the feature is that the
     * displayed duration is the billable one, not the wall-clock window. The
     * service still returns the raw values so admins can audit, and so the
     * service stays a pure mapping; this controller does the role-specific
     * redaction at the API boundary.
     */
    private static function stripRawTimesWhenRounded(array $cleaningDays): array
    {
        foreach ($cleaningDays as &$day) {
            if (!isset($day['cleanings']) || !is_array($day['cleanings'])) {
                continue;
            }
            foreach ($day['cleanings'] as &$cleaning) {
                if (($cleaning['roundedMinutes'] ?? null) !== null) {
                    $cleaning['startTime'] = null;
                    $cleaning['endTime'] = null;
                    $cleaning['rawMinutes'] = null;
                }
            }
            unset($cleaning);
        }
        unset($day);
        return $cleaningDays;
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
