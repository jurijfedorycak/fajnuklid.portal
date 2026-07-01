<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\CompanyRepository;
use App\Services\AttendanceOverviewService;
use App\Services\AttendanceSummaryService;
use App\Services\DemoAttendanceService;
use App\Services\FreshQRService;

class AttendanceController extends Controller
{
    private FreshQRService $freshqr;
    private ClientRepository $clientRepo;
    private CompanyRepository $companyRepo;

    public function __construct()
    {
        $this->freshqr = new FreshQRService();
        $this->clientRepo = new ClientRepository();
        $this->companyRepo = new CompanyRepository();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];
        $isAdmin = (bool) ($user['is_admin'] ?? false);

        $year = self::clampYear((int) ($request->query('year') ?? date('Y')));
        $month = self::clampMonth((int) ($request->query('month') ?? date('m')));

        // Admin "preview as client" — render the calendar exactly as the chosen
        // client would see it (rounding applied, raw times stripped, demo data
        // when demo). Only admins may pass this parameter; for non-admins it is
        // silently ignored so a tampered query string can't unlock another
        // client's view.
        $previewClientId = self::parsePreviewClientId($request->query('previewClientId'));
        if ($isAdmin && $previewClientId !== null) {
            $this->respondWithPreview($previewClientId, $year, $month);
            return;
        }

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

        $cleaningDays = self::applyRoundingRedactions($result['cleaningDays'], !$isAdmin);

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
     * Docházka overview: visit counts + worked-time totals for a selectable
     * period (day/week/month/quarter/year) with the previous equivalent period
     * as a comparison baseline. Separate from index() so the period switcher can
     * refetch just the aggregates without rebuilding the calendar.
     *
     * The payload never carries scan times or employee names — only counts and
     * summed (rounded/billable) minutes — so no rounding redaction is needed.
     */
    public function summary(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];
        $isAdmin = (bool) ($user['is_admin'] ?? false);

        $period = AttendanceOverviewService::normalisePeriod($request->query('period'));

        $previewClientId = self::parsePreviewClientId($request->query('previewClientId'));
        if ($isAdmin && $previewClientId !== null) {
            $this->respondSummaryPreview($previewClientId, $period);
            return;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));

        $client = $this->clientRepo->findByUserId($userId);
        if ($client !== null && (bool) $client['is_demo']) {
            $this->respondSummaryFromDemo($period, $today, null);
            return;
        }

        $companies = $this->companyRepo->findByUserId($userId);
        $this->respondSummaryFromCompanies($companies, $period, $today, null);
    }

    /**
     * Admin "preview as client" for the overview — mirrors respondWithPreview
     * but for the aggregate figures.
     */
    private function respondSummaryPreview(int $clientId, string $period): void
    {
        $client = $this->clientRepo->findById($clientId);
        if ($client === null) {
            Response::error('Klient nenalezen', 404);
            return;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        $previewMeta = [
            'clientId' => (int) $client['id'],
            'clientName' => (string) $client['display_name'],
        ];

        if ((bool) $client['is_demo']) {
            $this->respondSummaryFromDemo($period, $today, $previewMeta);
            return;
        }

        $companies = $this->companyRepo->findByClientId((int) $client['id']);
        $this->respondSummaryFromCompanies($companies, $period, $today, $previewMeta);
    }

    /**
     * @param array<array<string,mixed>> $companies
     * @param array<string,mixed>|null   $previewMeta
     */
    private function respondSummaryFromCompanies(
        array $companies,
        string $period,
        \DateTimeImmutable $today,
        ?array $previewMeta
    ): void {
        $current = AttendanceOverviewService::currentRange($period, $today);
        $previous = AttendanceOverviewService::previousRange($period, $today);

        // previous always precedes current → one fetch over the union covers both.
        $result = $this->freshqr->getCleaningDaysForCompaniesRange(
            $companies,
            $previous['from'],
            $current['to']
        );

        $this->emitSummary(
            (bool) $result['active'],
            $result['cleaningDays'],
            $result['companies'] ?? $companies,
            $current,
            $previous,
            $period,
            $result['error'] ?? null,
            $previewMeta
        );
    }

    /**
     * @param array<string,mixed>|null $previewMeta
     */
    private function respondSummaryFromDemo(
        string $period,
        \DateTimeImmutable $today,
        ?array $previewMeta
    ): void {
        $current = AttendanceOverviewService::currentRange($period, $today);
        $previous = AttendanceOverviewService::previousRange($period, $today);

        $cleaningDays = DemoAttendanceService::buildCleaningDaysForRange(
            $previous['from'],
            $current['to'],
            $today
        );

        $this->emitSummary(
            true,
            $cleaningDays,
            DemoAttendanceService::syntheticCompanies(),
            $current,
            $previous,
            $period,
            null,
            $previewMeta
        );
    }

    /**
     * Aggregate the current + previous slices out of one cleaningDays array and
     * emit the overview payload.
     *
     * @param array<array<string,mixed>>                            $cleaningDays
     * @param array<array<string,mixed>>                            $companies
     * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable}  $current
     * @param array{from:\DateTimeImmutable,to:\DateTimeImmutable}  $previous
     * @param array<string,mixed>|null                              $previewMeta
     */
    private function emitSummary(
        bool $active,
        array $cleaningDays,
        array $companies,
        array $current,
        array $previous,
        string $period,
        ?string $error,
        ?array $previewMeta
    ): void {
        $icoToName = [];
        foreach ($companies as $c) {
            $ico = isset($c['registration_number']) ? trim((string) $c['registration_number']) : '';
            if ($ico !== '') {
                $icoToName[$ico] = (string) ($c['name'] ?? '');
            }
        }

        $currentFrom = $current['from']->format('Y-m-d');
        $currentTo = $current['to']->format('Y-m-d');
        $previousFrom = $previous['from']->format('Y-m-d');
        $previousTo = $previous['to']->format('Y-m-d');

        $currentAgg = AttendanceOverviewService::aggregate($cleaningDays, $currentFrom, $currentTo, $icoToName);
        $previousAgg = AttendanceOverviewService::aggregate($cleaningDays, $previousFrom, $previousTo, $icoToName);

        $payload = [
            'freshqrActive' => $active,
            'period' => $period,
            'range' => ['from' => $currentFrom, 'to' => $currentTo],
            'previousRange' => ['from' => $previousFrom, 'to' => $previousTo],
            'current' => $currentAgg,
            // The FE only needs totals from the previous period to render deltas.
            'previous' => [
                'visitCount' => $previousAgg['visitCount'],
                'totalMinutes' => $previousAgg['totalMinutes'],
                'hasTimeData' => $previousAgg['hasTimeData'],
            ],
            'error' => $error,
        ];
        if ($previewMeta !== null) {
            $payload['preview'] = $previewMeta;
        }

        Response::success($payload);
    }

    /**
     * Render the calendar for an arbitrary client as that client's portal user
     * would see it. Used by the admin preview flow on the client edit page so
     * the admin can verify FreshQR mode + rounding settings before clients hit
     * the page.
     *
     * Always applies the client-view redactions (rounded end-time swap, raw
     * minutes hidden, ongoing-with-rules drops startTime) — the whole point is
     * to render the client's view, not the admin's audit view.
     */
    private function respondWithPreview(int $clientId, int $year, int $month): void
    {
        $client = $this->clientRepo->findById($clientId);
        if ($client === null) {
            Response::error('Klient nenalezen', 404);
            return;
        }

        if ((bool) $client['is_demo']) {
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
                'preview' => [
                    'clientId' => (int) $client['id'],
                    'clientName' => (string) $client['display_name'],
                ],
            ]);
            return;
        }

        $companies = $this->companyRepo->findByClientId((int) $client['id']);
        $result = $this->freshqr->getCleaningDaysForCompanies($companies, $year, $month);

        $hourlySummary = AttendanceSummaryService::buildHourlySummary(
            $result['companies'] ?? [],
            $result['cleaningDays']
        );

        // Preview is intentionally client-eye: even though the requester is an
        // admin, the rounded display takes over here so what they see matches
        // the customer's page byte-for-byte. Admins keep access to raw data in
        // the regular (non-preview) calendar.
        $cleaningDays = self::applyRoundingRedactions($result['cleaningDays'], true);

        Response::success([
            'freshqrActive' => $result['active'],
            'cleaningDays' => $cleaningDays,
            'hourlySummary' => $hourlySummary,
            'error' => $result['error'] ?? null,
            'year' => $year,
            'month' => $month,
            'preview' => [
                'clientId' => (int) $client['id'],
                'clientName' => (string) $client['display_name'],
            ],
        ]);
    }

    private static function parsePreviewClientId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    /**
     * Apply role-aware display redactions tied to per-IČO rounding rules.
     *
     * Two rules, applied in this order:
     *
     *   1. When the client view is requested AND the cleaning has a rounded
     *      duration, swap `endTime` for `roundedEndTime` so the displayed
     *      range (startTime → endTime) adds up to the billed duration. Null
     *      out `rawMinutes` so the client never sees the un-rounded number
     *      alongside the rounded one. `startTime` stays raw — the cleaner's
     *      actual arrival is a stable anchor and never shifts.
     *
     *   2. For every role: if the cleaning is ongoing AND the IČO has any
     *      rounding rules configured, null out `startTime`. The display
     *      reasoning is data stability — the rounded `endTime` we'll show
     *      after the cleaning ends depends on `startTime`, so committing to
     *      a "started at X" while the rules haven't yet been applied invites
     *      the value drifting once they kick in. Showing only "Probíhá"
     *      while ongoing keeps the badge truthful.
     *
     * Always strips the internal `roundedEndTime` and `hasRoundingRules`
     * fields — they're an interface between service and controller, never
     * meant to cross the API boundary.
     *
     * Day-level `ongoing` flag is intentionally preserved, and the rules
     * are per-cleaning, so a basic-mode day (empty cleanings[]) is unaffected.
     */
    private static function applyRoundingRedactions(array $cleaningDays, bool $clientView): array
    {
        foreach ($cleaningDays as &$day) {
            if (!isset($day['cleanings']) || !is_array($day['cleanings'])) {
                continue;
            }
            foreach ($day['cleanings'] as &$cleaning) {
                $hasRules = (bool) ($cleaning['hasRoundingRules'] ?? false);
                $roundedEnd = $cleaning['roundedEndTime'] ?? null;
                $isOngoing = (bool) ($cleaning['ongoing'] ?? false);
                $roundedMin = $cleaning['roundedMinutes'] ?? null;

                if ($clientView && $roundedMin !== null) {
                    if ($roundedEnd !== null) {
                        $cleaning['endTime'] = $roundedEnd;
                    }
                    $cleaning['rawMinutes'] = null;
                }

                if ($isOngoing && $hasRules) {
                    $cleaning['startTime'] = null;
                }

                unset($cleaning['roundedEndTime'], $cleaning['hasRoundingRules']);
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
