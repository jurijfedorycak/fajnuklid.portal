<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Config;

/**
 * READ-ONLY FreshQR integration. Bearer-token auth (no OAuth). request() is
 * hard-wired to GET so accidental writes raise immediately; every downstream
 * helper goes through it.
 */
class FreshQRClient
{
    private const RESPONSE_BODY_LIMIT = 2000;
    private const MAX_ATTEMPTS = 2;

    /**
     * Longest a single attendance entry (one scan-in/scan-out pair) may last
     * before it's treated as a forgotten scan-out rather than real work. This
     * is the discriminator that lets genuine overnight cleanings through while
     * still dropping stuck scans: a cross-midnight boundary is normal, an entry
     * longer than this is not. The cap is PER ENTRY, not per day — a large site
     * with a separate morning and evening shift produces two entries, each
     * validated on its own, so their combined day may exceed this. 12 hours.
     */
    public const MAX_ENTRY_MINUTES = 720;

    private string $apiUrl;
    private string $apiKey;
    private ?array $lastError = null;

    public function __construct()
    {
        $this->apiUrl = rtrim((string) Config::get('FRESHQR_API_URL', 'https://api.freshqr.online'), '/');
        $this->apiKey = (string) Config::get('FRESHQR_API_KEY', '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    public function resetLastError(): void
    {
        $this->lastError = null;
    }

    private function recordError(
        string $context,
        string $method,
        string $url,
        int $httpCode,
        string $curlError,
        string $responseBody
    ): void {
        $truncated = strlen($responseBody) > self::RESPONSE_BODY_LIMIT
            ? substr($responseBody, 0, self::RESPONSE_BODY_LIMIT) . '… [truncated]'
            : $responseBody;

        $this->lastError = [
            'context' => $context,
            'method' => $method,
            'url' => $url,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_body' => $truncated,
            'timestamp' => date('c'),
        ];

        error_log(sprintf(
            'FreshQR %s failed: %s %s -> HTTP %d%s body: %s',
            $context,
            $method,
            $url,
            $httpCode,
            $curlError !== '' ? " cURL: $curlError" : '',
            substr($responseBody, 0, 500)
        ));
    }

    private function request(string $method, string $endpoint, array $params = []): ?array
    {
        if ($method !== 'GET') {
            throw new \LogicException(
                "FreshQRClient is read-only; refusing '{$method} {$endpoint}'. "
                . 'If a write operation is ever required, add it as an explicit new method.'
            );
        }

        if (!$this->isConfigured()) {
            $url = $this->apiUrl . $endpoint;
            $this->lastError = [
                'context' => 'configuration',
                'method' => $method,
                'url' => $url,
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => 'FRESHQR_API_KEY není v .env nastaven.',
                'timestamp' => date('c'),
            ];
            return null;
        }

        $url = $this->apiUrl . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $responseBody = '';
        $httpCode = 0;
        $curlError = '';

        // One retry on transient failure (cURL error or 5xx). With no caching
        // in front, a single blip would otherwise empty the whole calendar.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $responseBody = is_string($response) ? $response : '';

            $transient = $curlError !== '' || $httpCode >= 500;
            if (!$transient) {
                break;
            }
        }

        if ($curlError !== '') {
            $this->recordError('API call', $method, $url, $httpCode, $curlError, $responseBody);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->recordError('API call', $method, $url, $httpCode, '', $responseBody);
            return null;
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->recordError('JSON parsing', $method, $url, $httpCode, '', $responseBody);
            return null;
        }

        return $decoded;
    }

    /**
     * Fetch per-day project attendance records for the given year/month.
     *
     * FreshQR /v1/reports/projects returns one record per (employee, date,
     * project) tuple with first_scan_time / last_scan_time / worked_hours. The
     * service layer uses date + project name + employee.personal_number to
     * build the calendar and last_scan_time to decide whether an earlier
     * project has been superseded; nothing else crosses the wire to the
     * client.
     *
     * Month is required — FreshQR rejects year-only queries with HTTP 400
     * ("Missing required parameter: month"). Multi-month windows go through
     * getProjectReportsForMonths().
     */
    public function getProjectReports(int $year, int $month): ?array
    {
        $response = $this->request('GET', '/v1/reports/projects', ['year' => $year, 'month' => $month]);

        if ($response === null) {
            return null;
        }

        return $this->extractReportData($response, $this->apiUrl . '/v1/reports/projects');
    }

    /**
     * Parallel multi-month variant of getProjectReports() used by the overview
     * ranges (week/quarter/year spans). FreshQR only serves month-scoped report
     * queries, so a range means one request per month; curl_multi runs them
     * concurrently, keeping a two-year window at roughly one round-trip of
     * wall-clock latency instead of twenty-four sequential ones.
     *
     * Mirrors request()'s per-call behaviour: bearer auth, 30s timeout, one
     * retry on transient failure (cURL error or 5xx), errors recorded via
     * recordError(). A failed month comes back as null so callers can
     * distinguish "quiet month" ([]) from "fetch failed" and degrade to a
     * partial result instead of dropping the whole window.
     *
     * @param  list<array{0:int,1:int}> $months  [year, month] pairs
     * @return array<string,?array>              'YYYY-MM' => records | null (failed)
     */
    public function getProjectReportsForMonths(array $months): array
    {
        $pending = [];
        foreach ($months as [$year, $month]) {
            $pending[sprintf('%04d-%02d', $year, $month)] = ['year' => $year, 'month' => $month];
        }
        if ($pending === []) {
            return [];
        }

        if (!$this->isConfigured()) {
            $this->lastError = [
                'context' => 'configuration',
                'method' => 'GET',
                'url' => $this->apiUrl . '/v1/reports/projects',
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => 'FRESHQR_API_KEY není v .env nastaven.',
                'timestamp' => date('c'),
            ];
            return array_fill_keys(array_keys($pending), null);
        }

        $results = [];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $pending !== []; $attempt++) {
            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;
            $multi = curl_multi_init();
            $handles = [];

            foreach ($pending as $key => $params) {
                $url = $this->apiUrl . '/v1/reports/projects?' . http_build_query($params);
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Accept: application/json',
                    ],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[$key] = ['ch' => $ch, 'url' => $url];
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // A multi-driven transfer's result code isn't copied into the easy
            // handle until the completion messages are drained — without this,
            // curl_error() below reads '' for timeouts/refused connections and
            // those failures would never qualify for the retry pass.
            while (curl_multi_info_read($multi) !== false) {
            }

            $retry = [];
            foreach ($handles as $key => $handle) {
                $body = curl_multi_getcontent($handle['ch']);
                $body = is_string($body) ? $body : '';
                $httpCode = (int) curl_getinfo($handle['ch'], CURLINFO_HTTP_CODE);
                $curlError = curl_error($handle['ch']);
                // No curl_close(): deprecated since PHP 8.5 and a no-op since 8.0
                // (handles are objects freed by GC once out of scope). Removing
                // it from the multi handle is the only cleanup still required.
                curl_multi_remove_handle($multi, $handle['ch']);

                $transient = $curlError !== '' || $httpCode >= 500;
                if ($transient && !$isLastAttempt) {
                    $retry[$key] = $pending[$key];
                    continue;
                }

                $results[$key] = $this->parseReportsBody($body, $handle['url'], $httpCode, $curlError);
            }

            // No curl_multi_close(): same story as curl_close() above — deprecated
            // in 8.5, no-op since 8.0. $multi is freed by GC when it's reassigned
            // by the next attempt's curl_multi_init() or falls out of scope.
            $pending = $retry;
        }

        return $results;
    }

    /**
     * Shared tail of a /v1/reports/projects response for the curl_multi path —
     * the same status/JSON handling request() applies to single calls.
     */
    private function parseReportsBody(string $body, string $url, int $httpCode, string $curlError): ?array
    {
        if ($curlError !== '') {
            $this->recordError('API call', 'GET', $url, $httpCode, $curlError, $body);
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->recordError('API call', 'GET', $url, $httpCode, '', $body);
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->recordError('JSON parsing', 'GET', $url, $httpCode, '', $body);
            return null;
        }
        return $this->extractReportData($decoded, $url);
    }

    /**
     * FreshQR omits `data` on quiet months; treat that the same as an empty
     * array. A non-array `data` is malformed though — surface it as an error
     * rather than handing the service a value it can't iterate.
     */
    private function extractReportData(array $response, string $url): ?array
    {
        if (!array_key_exists('data', $response)) {
            return [];
        }
        if (!is_array($response['data'])) {
            $this->lastError = [
                'context' => 'response shape',
                'method' => 'GET',
                'url' => $url,
                'http_code' => 200,
                'curl_error' => '',
                'response_body' => 'Pole "data" v odpovědi FreshQR není seznam.',
                'timestamp' => date('c'),
            ];
            return null;
        }
        return $response['data'];
    }

    /**
     * Fetch in-progress (TimeTo IS NULL) scans for the last few days and
     * reshape them into the same record format as getProjectReports().
     *
     * Why we need this: /v1/reports/projects reads from a materialized cache
     * that filters out rows whose last_scan_time is null, so cleanings that
     * are currently happening never appear in that endpoint. The
     * /v1/reports/attendance-raw endpoint queries the live Attendance table
     * directly and DOES expose open scans, but uses a different field shape
     * (`TimeFrom` / `TimeTo` / `CompanyEmployeeId` / `TaskName1`) and omits
     * `personal_number`. We map CompanyEmployeeId → personal_number via
     * /v1/employees, then emit records that the service layer can merge with
     * getProjectReports() output without branching.
     *
     * Returns []|null. null signals a transient failure on either underlying
     * call — callers can degrade gracefully (skip the augmentation, still show
     * the cached historical data).
     *
     * @return list<array<string,mixed>>|null
     */
    public function getOngoingProjectReports(): ?array
    {
        // Two days covers today + any cross-midnight cleaning that started
        // yesterday and is still open. Bigger windows just waste payload —
        // attendance-raw's filtered-list is fully scanned client-side.
        $raw = $this->request('GET', '/v1/reports/attendance-raw', ['days' => 2]);
        if ($raw === null) {
            return null;
        }

        if (!isset($raw['data']) || !is_array($raw['data'])) {
            return [];
        }

        $ongoing = [];
        foreach ($raw['data'] as $row) {
            if (!is_array($row) || ($row['TimeTo'] ?? null) !== null) {
                continue;
            }
            $ongoing[] = $row;
        }

        if ($ongoing === []) {
            return [];
        }

        $idToPersonal = $this->fetchEmployeePersonalIdMap();
        if ($idToPersonal === null) {
            // Couldn't resolve the personal_number mapping — the rest of the
            // pipeline keys off personal_number for both the allow-list and
            // the per-IČO mode lookup, so emitting unmapped records would
            // either be dropped (best case) or break matching. Treat as a
            // transient failure.
            return null;
        }

        $result = [];
        foreach ($ongoing as $row) {
            $reshaped = self::reshapeRawAttendanceRecord($row, $idToPersonal);
            if ($reshaped !== null) {
                $result[] = $reshaped;
            }
        }
        return $result;
    }

    /**
     * Map FreshQR's CompanyEmployeeId (used in attendance-raw rows) to the
     * `personal_number` value the rest of the integration matches against.
     * Employees without a personal_number assigned are intentionally absent
     * from the map — they're filtered out at the matching step anyway.
     *
     * FreshQR's /v1/employees endpoint returns the entire active employee
     * list in one response (no LIMIT/OFFSET, no page params accepted) — see
     * QrAttendanceWeb/public-api/v1/endpoints/employees.php handleGetEmployees().
     * Should that contract ever change, this method has to grow paging support.
     *
     * @return array<int,string>|null  null = transient API failure
     */
    private function fetchEmployeePersonalIdMap(): ?array
    {
        $response = $this->request('GET', '/v1/employees', []);
        if ($response === null) {
            return null;
        }
        if (!isset($response['data']) || !is_array($response['data'])) {
            return [];
        }
        $map = [];
        foreach ($response['data'] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $id = isset($e['id']) ? (int) $e['id'] : 0;
            $personal = $e['personal_number'] ?? null;
            if ($id <= 0 || !is_string($personal) || $personal === '') {
                continue;
            }
            $map[$id] = $personal;
        }
        return $map;
    }

    /**
     * Fetch every attendance scan pair (open AND closed) whose TimeFrom falls in
     * [$from, $to] and reshape each into the record format buildCleaningDays
     * consumes — one record per pair, so same-object repeat visits stay separate
     * and each duration is computed from its own scan-in/out (no between-visit
     * gap). This is the detailed-mode source of truth; unlike /v1/reports/projects
     * it never collapses a day's visits into a single first/last span.
     *
     * The endpoint caps a single query at 366 days, so a wider window (the
     * year-over-year overview) is walked in <=365-day chunks. The employee
     * id → personal_number map is fetched once and reused across chunks.
     *
     * Returns []|null. null signals a transient failure (employee map or any
     * chunk unreachable) so callers surface a banner instead of a half-empty
     * calendar built from a partial range.
     *
     * @return list<array<string,mixed>>|null
     */
    public function getAttendanceRawForRange(\DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        if (!$this->isConfigured()) {
            $this->lastError = [
                'context' => 'configuration',
                'method' => 'GET',
                'url' => $this->apiUrl . '/v1/reports/attendance-raw',
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => 'FRESHQR_API_KEY není v .env nastaven.',
                'timestamp' => date('c'),
            ];
            return null;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $idToPersonal = $this->fetchEmployeePersonalIdMap();
        if ($idToPersonal === null) {
            return null;
        }

        $result = [];
        // <=365-day chunks keep every request under the endpoint's 366-day span
        // guard, with margin for DST-induced drift in the day arithmetic.
        $chunkStart = $from;
        while ($chunkStart <= $to) {
            $chunkEnd = $chunkStart->modify('+365 days');
            if ($chunkEnd > $to) {
                $chunkEnd = $to;
            }

            $raw = $this->request('GET', '/v1/reports/attendance-raw', [
                'date_from' => $chunkStart->format('Y-m-d'),
                'date_to' => $chunkEnd->format('Y-m-d'),
            ]);
            if ($raw === null) {
                return null;
            }

            if (isset($raw['data']) && is_array($raw['data'])) {
                foreach ($raw['data'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $reshaped = self::reshapeRawAttendanceRecord($row, $idToPersonal);
                    if ($reshaped !== null) {
                        $result[] = $reshaped;
                    }
                }
            }

            $chunkStart = $chunkEnd->modify('+1 day');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row             attendance-raw record
     * @param array<int,string>   $idToPersonal    CompanyEmployeeId → personal_number
     * @return array<string,mixed>|null
     */
    private static function reshapeRawAttendanceRecord(array $row, array $idToPersonal): ?array
    {
        $timeFrom = $row['TimeFrom'] ?? null;
        if (!is_string($timeFrom) || !preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $timeFrom, $m)) {
            return null;
        }
        $taskName = $row['TaskName1'] ?? null;
        if (!is_string($taskName) || $taskName === '') {
            return null;
        }
        $companyEmployeeId = isset($row['CompanyEmployeeId']) ? (int) $row['CompanyEmployeeId'] : 0;
        $personal = $idToPersonal[$companyEmployeeId] ?? null;
        if ($personal === null) {
            return null;
        }

        // Closed pair → the scan-out time (time-of-day only) becomes
        // last_scan_time; open pair (TimeTo null/unparseable) stays null so the
        // record reads as "still on-site" and, while recent, ongoing. Every entry
        // is anchored to the scan-IN day (`$m[1]`): an overnight cleaning belongs
        // to the day it started, so a scan-out after midnight keeps the entry on
        // the prior day (last_scan_time will read lexically earlier than
        // first_scan_time — the crossing is derivable from that, so no separate
        // flag is stored; duration comes from duration_minutes, not the times).
        //
        // A cross-midnight pair is a genuine overnight shift, not an anomaly to
        // drop — Fajnúklid's larger sites do run cleanings that finish past
        // midnight. The real anomaly is a forgotten scan-out, told apart by
        // DURATION rather than by the calendar-day boundary: an entry longer
        // than MAX_ENTRY_MINUTES (or inverted) is dropped below. FreshQR's own
        // DurationMinutes is authoritative (spans midnight correctly); we only
        // recompute from the timestamps when that field is absent.
        $last = null;
        $timeTo = $row['TimeTo'] ?? null;
        if (is_string($timeTo) && preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $timeTo, $mto)) {
            $last = $mto[2];
        }

        // Zero minutes on a CLOSED pair is scan noise, not a visit: extra taps
        // right after a scan-out create an in+out pair seconds apart (seen live
        // after an overnight shift — the ghost pair then painted the follow-up
        // day as a phantom cleaning). Open pairs have a null duration and are
        // unaffected by this guard.
        $durationMinutes = self::entryDurationMinutes($row, $timeFrom, $timeTo);
        if ($durationMinutes !== null && ($durationMinutes <= 0 || $durationMinutes > self::MAX_ENTRY_MINUTES)) {
            return null;
        }

        return [
            'date' => $m[1],
            'project' => ['name' => $taskName],
            'employee' => ['personal_number' => $personal],
            'first_scan_time' => $m[2],
            'last_scan_time' => $last,
            'duration_minutes' => $durationMinutes,
        ];
    }

    /**
     * Duration of a single attendance entry in whole minutes, or null when it
     * can't be determined (open pair, malformed timestamps).
     *
     * Prefers FreshQR's precomputed `DurationMinutes` (TIMESTAMPDIFF over the
     * scan pair — authoritative and correct across midnight). Falls back to the
     * timestamp difference only when that field is missing or malformed. Zero
     * and negative values are passed through so the caller can drop zero-length
     * (double-scan ghost) and inverted pairs.
     *
     * @param array<string,mixed> $row
     */
    private static function entryDurationMinutes(array $row, string $timeFrom, ?string $timeTo): ?int
    {
        $reported = $row['DurationMinutes'] ?? null;
        if (is_int($reported)) {
            return $reported;
        }
        if (is_string($reported) && preg_match('/^-?\d+$/', $reported) === 1) {
            return (int) $reported;
        }

        if (!is_string($timeTo) || $timeTo === '') {
            return null;
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timeFrom);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timeTo);
        if ($from === false || $to === false) {
            return null;
        }
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
