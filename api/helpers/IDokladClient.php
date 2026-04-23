<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Config\Config;
use App\Repositories\IDokladTokenRepository;

/**
 * READ-ONLY iDoklad integration.
 *
 * This client never creates, modifies or deletes any entity in iDoklad.
 * All outbound traffic is one of:
 *   - POST /server/connect/token   (OAuth2 client_credentials, auth only)
 *   - GET  /IssuedInvoices          (list invoices)
 *   - GET  /IssuedInvoices/{id}/GetPdf (fetch invoice PDF)
 *
 * The request() helper intentionally only accepts the 'GET' verb so that any
 * accidental attempt to mutate iDoklad state raises immediately.
 */
class IDokladClient
{
    private const TOKEN_URL = 'https://identity.idoklad.cz/server/connect/token';

    private const RESPONSE_BODY_LIMIT = 2000;

    // Invoices issued before this date are not synced from iDoklad.
    public const SYNC_FROM_DATE = '2026-01-01';

    private IDokladTokenRepository $tokenRepo;
    private string $clientId;
    private string $clientSecret;
    private string $apiUrl;
    private ?array $lastError = null;

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
            'iDoklad %s failed: %s %s -> HTTP %d%s body: %s',
            $context,
            $method,
            $url,
            $httpCode,
            $curlError !== '' ? " cURL: $curlError" : '',
            substr($responseBody, 0, 500)
        ));
    }

    private function getAccessToken(): ?string
    {
        $validToken = $this->tokenRepo->getValidToken();
        if ($validToken !== null) {
            return $validToken;
        }

        return $this->requestNewToken();
    }

    private function requestNewToken(): ?string
    {
        if (!$this->isConfigured()) {
            $this->lastError = [
                'context' => 'token request',
                'method' => 'POST',
                'url' => self::TOKEN_URL,
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => 'IDOKLAD_CLIENT_ID nebo IDOKLAD_CLIENT_SECRET nejsou v .env nastaveny.',
                'timestamp' => date('c'),
            ];
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

        $responseBody = is_string($response) ? $response : '';

        if ($curlError !== '' || $httpCode !== 200) {
            $this->recordError('token request', 'POST', self::TOKEN_URL, $httpCode, $curlError, $responseBody);
            return null;
        }

        $data = json_decode($responseBody, true);

        if (!isset($data['access_token']) || !isset($data['expires_in'])) {
            $this->recordError('token response parsing', 'POST', self::TOKEN_URL, $httpCode, '', $responseBody);
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
        // Enforce the read-only contract at runtime.
        if ($method !== 'GET') {
            throw new \LogicException(
                "IDokladClient is read-only; refusing '{$method} {$endpoint}'. "
                . 'If a write operation is ever required, add it as an explicit new method.'
            );
        }

        $token = $this->getAccessToken();

        if ($token === null) {
            return null;
        }

        $url = $this->apiUrl . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $responseBody = is_string($response) ? $response : '';

        if ($curlError !== '') {
            $this->recordError('API call', $method, $url, $httpCode, $curlError, $responseBody);
            return null;
        }

        if ($httpCode === 401) {
            // Bearer was rejected — purge cached tokens so the next call re-auths from scratch.
            $this->tokenRepo->deleteAllTokens();
            $this->recordError('API call (token rejected)', $method, $url, $httpCode, '', $responseBody);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->recordError('API call', $method, $url, $httpCode, '', $responseBody);
            return null;
        }

        return json_decode($responseBody, true);
    }

    /**
     * iDoklad v3 /IssuedInvoices does not support filtering by PartnerIdentificationNumber
     * (IČO). The supported partner column is PartnerId, so we must first resolve the
     * iDoklad Contact Id from the IČO via /Contacts before we can query invoices.
     */
    private function getContactIdByIco(string $sanitizedIco): ?int
    {
        $params = [
            'filter' => "IdentificationNumber~eq~'" . $sanitizedIco . "'",
            'page' => 1,
            'pageSize' => 1,
        ];

        $response = $this->request('GET', '/Contacts', $params);

        if ($response === null) {
            return null;
        }

        $items = $response['Items'] ?? [];

        if (empty($items)) {
            return null;
        }

        $id = (int) ($items[0]['Id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function getInvoicesByPartnerId(int $partnerId, int $page = 1, int $pageSize = 100): ?array
    {
        $params = [
            'filter' => '(PartnerId~eq~' . $partnerId . ")~and~(DateOfIssue~gte~'" . self::SYNC_FROM_DATE . "')",
            'page' => $page,
            'pageSize' => $pageSize,
            'sort' => 'DateOfIssue~desc',
        ];

        return $this->request('GET', '/IssuedInvoices', $params);
    }

    public function getInvoicesByIco(string $ico, int $page = 1, int $pageSize = 100): ?array
    {
        $this->resetLastError();

        $sanitizedIco = preg_replace('/[^0-9]/', '', $ico);

        if ($sanitizedIco === '') {
            $this->lastError = [
                'context' => 'input validation',
                'method' => 'GET',
                'url' => $this->apiUrl . '/IssuedInvoices',
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => sprintf('IČO "%s" po sanitaci neobsahuje žádné číslice.', $ico),
                'timestamp' => date('c'),
            ];
            return null;
        }

        $partnerId = $this->getContactIdByIco($sanitizedIco);

        if ($partnerId === null) {
            // Propagate hard API errors to the caller; otherwise treat "no matching
            // contact in iDoklad" as a zero-result page so pagination terminates cleanly.
            if ($this->lastError !== null) {
                return null;
            }
            return ['Items' => [], 'TotalPages' => 0];
        }

        return $this->getInvoicesByPartnerId($partnerId, $page, $pageSize);
    }

    public function getAllInvoicesByIco(string $ico): array
    {
        $this->resetLastError();

        $sanitizedIco = preg_replace('/[^0-9]/', '', $ico);

        if ($sanitizedIco === '') {
            $this->lastError = [
                'context' => 'input validation',
                'method' => 'GET',
                'url' => $this->apiUrl . '/IssuedInvoices',
                'http_code' => 0,
                'curl_error' => '',
                'response_body' => sprintf('IČO "%s" po sanitaci neobsahuje žádné číslice.', $ico),
                'timestamp' => date('c'),
            ];
            return [];
        }

        $partnerId = $this->getContactIdByIco($sanitizedIco);

        if ($partnerId === null) {
            // lastError is either null (no contact → no invoices) or set by the
            // /Contacts call. Either way the caller gets an empty list.
            return [];
        }

        $allInvoices = [];
        $page = 1;
        $pageSize = 100;

        do {
            $response = $this->getInvoicesByPartnerId($partnerId, $page, $pageSize);

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
        $this->resetLastError();

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

        $responseBody = is_string($response) ? $response : '';

        if ($curlError !== '' || $httpCode !== 200) {
            $this->recordError('PDF download', 'GET', $url, $httpCode, $curlError, $responseBody);
            return null;
        }

        // Verify we got a PDF
        if (strpos((string) $contentType, 'application/pdf') === false && strpos($responseBody, '%PDF') !== 0) {
            $this->recordError(
                'PDF download (unexpected content-type)',
                'GET',
                $url,
                $httpCode,
                '',
                'Content-Type: ' . (string) $contentType
            );
            return null;
        }

        return $responseBody;
    }

    public function getInvoiceById(int $invoiceId): ?array
    {
        $this->resetLastError();

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
