<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Config;
use App\Repositories\IDokladTokenRepository;

class IDokladClient
{
    private const TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';

    private IDokladTokenRepository $tokenRepo;
    private string $clientId;
    private string $clientSecret;
    private string $apiUrl;

    public function __construct()
    {
        $this->tokenRepo = new IDokladTokenRepository();
        $this->clientId = Config::get('IDOKLAD_CLIENT_ID', '');
        $this->clientSecret = Config::get('IDOKLAD_CLIENT_SECRET', '');
        $this->apiUrl = Config::get('IDOKLAD_API_URL', 'https://api.idoklad.cz/v3');
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    private function getAccessToken(): ?string
    {
        // Check for valid cached token
        $validToken = $this->tokenRepo->getValidToken();
        if ($validToken !== null) {
            return $validToken;
        }

        // Request new token
        return $this->requestNewToken();
    }

    private function requestNewToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'idoklad_api',
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '' || $httpCode !== 200) {
            error_log("iDoklad token request failed: HTTP $httpCode, Error: $curlError");
            return null;
        }

        $data = json_decode($response, true);

        if (!isset($data['access_token']) || !isset($data['expires_in'])) {
            error_log("iDoklad token response invalid: " . $response);
            return null;
        }

        // Calculate expiration time
        $expiresAt = new \DateTime();
        $expiresAt->modify('+' . (int) $data['expires_in'] . ' seconds');

        // Save token to database
        $this->tokenRepo->saveToken($data['access_token'], $expiresAt);

        // Clean up old expired tokens
        $this->tokenRepo->deleteExpiredTokens();

        return $data['access_token'];
    }

    private function request(string $method, string $endpoint, array $params = []): ?array
    {
        $token = $this->getAccessToken();

        if ($token === null) {
            return null;
        }

        $url = $this->apiUrl . $endpoint;

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($params);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log("iDoklad API request failed: $curlError");
            return null;
        }

        if ($httpCode === 401) {
            // Token might be invalid, try to refresh
            $this->tokenRepo->deleteAllTokens();
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("iDoklad API returned HTTP $httpCode: $response");
            return null;
        }

        return json_decode($response, true);
    }

    public function getInvoicesByIco(string $ico, int $page = 1, int $pageSize = 100): ?array
    {
        // Sanitize ICO - should be numeric only (Czech IČO is 8 digits)
        $sanitizedIco = preg_replace('/[^0-9]/', '', $ico);

        if ($sanitizedIco === '') {
            return null;
        }

        $params = [
            'filter' => "PartnerIdentificationNumber~eq~'" . $sanitizedIco . "'",
            'page' => $page,
            'pageSize' => $pageSize,
            'sort' => 'DateOfIssue~desc',
        ];

        return $this->request('GET', '/IssuedInvoices', $params);
    }

    public function getAllInvoicesByIco(string $ico): array
    {
        $allInvoices = [];
        $page = 1;
        $pageSize = 100;

        do {
            $response = $this->getInvoicesByIco($ico, $page, $pageSize);

            if ($response === null) {
                break;
            }

            $items = $response['Items'] ?? [];
            $allInvoices = array_merge($allInvoices, $items);

            $totalPages = $response['TotalPages'] ?? 1;
            $page++;
        } while ($page <= $totalPages && $page <= 10); // Max 10 pages (1000 invoices)

        return $allInvoices;
    }

    public function getInvoicePdf(int $invoiceId): ?string
    {
        $token = $this->getAccessToken();

        if ($token === null) {
            return null;
        }

        $url = $this->apiUrl . "/IssuedInvoices/$invoiceId/GetPdf";

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/pdf',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '' || $httpCode !== 200) {
            error_log("iDoklad PDF download failed: HTTP $httpCode, Error: $curlError");
            return null;
        }

        // Verify we got a PDF
        if (strpos($contentType, 'application/pdf') === false && strpos($response, '%PDF') !== 0) {
            error_log("iDoklad PDF response is not a PDF: Content-Type: $contentType");
            return null;
        }

        return $response;
    }

    public function getInvoiceById(int $invoiceId): ?array
    {
        return $this->request('GET', "/IssuedInvoices/$invoiceId");
    }

    public static function calculatePaymentStatus(array $invoice): string
    {
        $isPaid = $invoice['IsPaid'] ?? $invoice['is_paid'] ?? false;

        if ($isPaid) {
            return 'paid';
        }

        $dateDue = $invoice['DateOfMaturity'] ?? $invoice['date_due'] ?? null;

        if ($dateDue === null) {
            return 'unpaid';
        }

        $dueDate = new \DateTime($dateDue);
        $today = new \DateTime('today');

        if ($dueDate < $today) {
            return 'overdue';
        }

        return 'unpaid';
    }

    public static function mapIdokladInvoice(array $idokladInvoice, int $companyId): array
    {
        $paymentStatus = self::calculatePaymentStatus($idokladInvoice);

        return [
            'idoklad_id' => (int) $idokladInvoice['Id'],
            'company_id' => $companyId,
            'document_number' => $idokladInvoice['DocumentNumber'] ?? '',
            'variable_symbol' => $idokladInvoice['VariableSymbol'] ?? null,
            'date_issued' => $idokladInvoice['DateOfIssue'] ?? date('Y-m-d'),
            'date_due' => $idokladInvoice['DateOfMaturity'] ?? date('Y-m-d'),
            'date_paid' => $idokladInvoice['DateOfPayment'] ?? null,
            'total_amount' => (float) ($idokladInvoice['TotalWithVat'] ?? 0),
            'currency_code' => $idokladInvoice['Currency']['Code'] ?? 'CZK',
            'is_paid' => (bool) ($idokladInvoice['IsPaid'] ?? false),
            'payment_status' => $paymentStatus,
            'description' => $idokladInvoice['Description'] ?? null,
        ];
    }
}
