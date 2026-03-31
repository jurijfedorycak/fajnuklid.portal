<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Config;
use App\Exceptions\ApiException;

/**
 * FreshQR integration client - Phase 2 placeholder
 *
 * This client will be used to fetch attendance data from FreshQR system.
 * Currently returns placeholder data until FreshQR integration is implemented.
 */
class FreshQRClient
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = Config::get('FRESHQR_API_URL', '');
        $this->apiKey = Config::get('FRESHQR_API_KEY', '');
    }

    public function getAttendanceByObject(int $objectId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Phase 2 placeholder - return empty array
        return [
            'message' => 'FreshQR integration coming soon',
            'data' => []
        ];
    }

    public function getAttendanceByEmployee(int $employeeId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Phase 2 placeholder - return empty array
        return [
            'message' => 'FreshQR integration coming soon',
            'data' => []
        ];
    }

    public function getAttendanceSummary(string $ico, ?string $month = null): array
    {
        // Phase 2 placeholder - return summary structure
        return [
            'message' => 'FreshQR integration coming soon',
            'ico' => $ico,
            'month' => $month ?? date('Y-m'),
            'summary' => [
                'total_hours' => 0,
                'total_visits' => 0,
                'objects' => []
            ]
        ];
    }

    private function request(string $method, string $endpoint, array $params = []): ?array
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            throw new ApiException('FreshQR credentials not configured');
        }

        $url = $this->apiUrl . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode >= 400) {
            return null;
        }

        return json_decode($response, true);
    }
}
