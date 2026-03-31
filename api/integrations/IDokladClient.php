<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Config\Config;
use App\Exceptions\ApiException;

class IDokladClient
{
    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->apiUrl = Config::get('IDOKLAD_API_URL', 'https://api.idoklad.cz/v3');
        $this->clientId = Config::get('IDOKLAD_CLIENT_ID', '');
        $this->clientSecret = Config::get('IDOKLAD_CLIENT_SECRET', '');
    }

    private function authenticate(): void
    {
        if ($this->accessToken) {
            return;
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ApiException('iDoklad credentials not configured');
        }

        $tokenUrl = 'https://identity.idoklad.cz/server/connect/token';

        $response = $this->httpRequest('POST', $tokenUrl, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'idoklad_api'
        ], false, true);

        if (!isset($response['access_token'])) {
            throw new ApiException('Failed to authenticate with iDoklad');
        }

        $this->accessToken = $response['access_token'];
    }

    public function getInvoicesByIco(string $ico): array
    {
        $this->authenticate();

        $filter = rawurlencode("PartnerIdentificationNumber~eq~'{$ico}'");
        $response = $this->httpRequest('GET', "{$this->apiUrl}/IssuedInvoices?filter={$filter}&pagesize=100");

        return array_map(function ($invoice) {
            return [
                'id' => $invoice['Id'],
                'document_number' => $invoice['DocumentNumber'],
                'date_issued' => $invoice['DateOfIssue'],
                'date_due' => $invoice['DateOfMaturity'],
                'date_paid' => $invoice['DateOfPayment'] ?? null,
                'total_amount' => $invoice['Prices']['TotalWithVat'],
                'currency' => $invoice['CurrencySymbol'],
                'partner_name' => $invoice['PartnerContact']['CompanyName'] ?? '',
                'partner_ico' => $invoice['PartnerContact']['IdentificationNumber'] ?? '',
                'is_paid' => $invoice['IsPaid'] ?? false,
                'description' => $invoice['Description'] ?? ''
            ];
        }, $response['Items'] ?? []);
    }

    public function getInvoice(string $id): ?array
    {
        $this->authenticate();

        $response = $this->httpRequest('GET', "{$this->apiUrl}/IssuedInvoices/{$id}");

        if (!$response) {
            return null;
        }

        return [
            'id' => $response['Id'],
            'document_number' => $response['DocumentNumber'],
            'date_issued' => $response['DateOfIssue'],
            'date_due' => $response['DateOfMaturity'],
            'date_paid' => $response['DateOfPayment'] ?? null,
            'total_amount' => $response['Prices']['TotalWithVat'],
            'currency' => $response['CurrencySymbol'],
            'partner_name' => $response['PartnerContact']['CompanyName'] ?? '',
            'partner_ico' => $response['PartnerContact']['IdentificationNumber'] ?? '',
            'is_paid' => $response['IsPaid'] ?? false
        ];
    }

    public function getInvoicePdf(string $id): string
    {
        $this->authenticate();

        $ch = curl_init("{$this->apiUrl}/IssuedInvoices/{$id}/GetPdf");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/pdf'
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new ApiException('Failed to download invoice PDF');
        }

        return $response;
    }

    private function httpRequest(
        string $method,
        string $url,
        array $data = [],
        bool $useAuth = true,
        bool $formEncoded = false
    ): ?array {
        $ch = curl_init($url);

        $headers = [];

        if ($useAuth && $this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);

            if ($formEncoded) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $headers[] = 'Accept: application/json';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode >= 400) {
            return null;
        }

        return json_decode($response, true);
    }
}
