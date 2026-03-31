<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Config;
use App\Exceptions\ApiException;

class AirtableClient
{
    private const API_URL = 'https://api.airtable.com/v0';

    private string $apiKey;
    private string $baseId;

    public function __construct()
    {
        $this->apiKey = Config::get('AIRTABLE_API_KEY', '');
        $this->baseId = Config::get('AIRTABLE_BASE_ID', '');
    }

    public function getRecords(string $table, array $params = []): array
    {
        $this->validateConfig();

        $queryString = http_build_query($params);
        $url = self::API_URL . "/{$this->baseId}/{$table}";

        if ($queryString) {
            $url .= '?' . $queryString;
        }

        $allRecords = [];
        $offset = null;

        do {
            $requestUrl = $offset ? "{$url}&offset={$offset}" : $url;
            $response = $this->request('GET', $requestUrl);

            if (isset($response['records'])) {
                $allRecords = array_merge($allRecords, $response['records']);
            }

            $offset = $response['offset'] ?? null;
        } while ($offset);

        return $allRecords;
    }

    public function getRecord(string $table, string $recordId): ?array
    {
        $this->validateConfig();

        $url = self::API_URL . "/{$this->baseId}/{$table}/{$recordId}";

        return $this->request('GET', $url);
    }

    public function createRecord(string $table, array $fields): ?array
    {
        $this->validateConfig();

        $url = self::API_URL . "/{$this->baseId}/{$table}";
        $data = ['fields' => $fields];

        return $this->request('POST', $url, $data);
    }

    public function updateRecord(string $table, string $recordId, array $fields): ?array
    {
        $this->validateConfig();

        $url = self::API_URL . "/{$this->baseId}/{$table}/{$recordId}";
        $data = ['fields' => $fields];

        return $this->request('PATCH', $url, $data);
    }

    public function deleteRecord(string $table, string $recordId): bool
    {
        $this->validateConfig();

        $url = self::API_URL . "/{$this->baseId}/{$table}/{$recordId}";
        $response = $this->request('DELETE', $url);

        return isset($response['deleted']) && $response['deleted'] === true;
    }

    private function validateConfig(): void
    {
        if (empty($this->apiKey) || empty($this->baseId)) {
            throw new ApiException('Airtable credentials not configured');
        }
    }

    private function request(string $method, string $url, ?array $data = null): ?array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($curlError) {
            throw new ApiException("Airtable API error: {$curlError}");
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true);
            $message = $decoded['error']['message'] ?? 'Unknown error';
            throw new ApiException("Airtable API error: {$message}");
        }

        return json_decode($response, true);
    }
}
