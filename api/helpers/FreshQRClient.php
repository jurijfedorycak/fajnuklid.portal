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
            curl_close($ch);

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
     * project) tuple with first_scan_time / last_scan_time / worked_hours. For
     * the portal we only need date + project name + employee.personal_number —
     * durations are stripped by the service layer.
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

        return $response['data'] ?? [];
    }
}
