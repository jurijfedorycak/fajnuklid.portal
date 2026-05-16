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
     * Month is optional: when null, the endpoint returns the whole year. We
     * always pass it to keep payload small.
     */
    public function getProjectReports(int $year, int $month): ?array
    {
        $response = $this->request('GET', '/v1/reports/projects', [
            'year' => $year,
            'month' => $month,
        ]);

        if ($response === null) {
            return null;
        }

        // FreshQR omits `data` on quiet months; treat that the same as an empty
        // array. A non-array `data` is malformed though — surface it as an
        // error rather than handing the service a value it can't iterate.
        if (!array_key_exists('data', $response)) {
            return [];
        }
        if (!is_array($response['data'])) {
            $this->lastError = [
                'context' => 'response shape',
                'method' => 'GET',
                'url' => $this->apiUrl . '/v1/reports/projects',
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
        return [
            'date' => $m[1],
            'project' => ['name' => $taskName],
            'employee' => ['personal_number' => $personal],
            'first_scan_time' => $m[2],
            'last_scan_time' => null,
        ];
    }
}
