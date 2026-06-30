<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CompanyRepository;
use App\Repositories\StaffContactRepository;
use App\Services\CompanyDocumentService;
use App\Services\R2StorageService;
use App\Exceptions\NotFoundException;

/**
 * Serves the client "Smlouvy a dokumenty" page: every document uploaded to each of the
 * user's companies (IČO), grouped per company. Files are streamed through an
 * authenticated, ownership-checked endpoint rather than a public proxy URL.
 */
class ContractController extends Controller
{
    private CompanyRepository $companyRepo;
    private StaffContactRepository $staffContactRepo;
    private CompanyDocumentService $documents;
    private R2StorageService $storage;

    public function __construct()
    {
        $this->companyRepo = new CompanyRepository();
        $this->staffContactRepo = new StaffContactRepository();
        $this->documents = new CompanyDocumentService();
        $this->storage = new R2StorageService();
    }

    public function index(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];

        $companies = $this->companyRepo->findByUserId($userId);
        $companyIds = array_map(static fn ($c) => (int) $c['id'], $companies);
        $documentsByCompany = $this->documents->listForCompaniesGrouped($companyIds);

        $companyPayload = [];
        $totalDocuments = 0;
        foreach ($companies as $company) {
            $cid = (int) $company['id'];
            $docs = $documentsByCompany[$cid] ?? [];
            $totalDocuments += count($docs);
            $companyPayload[] = [
                'id' => $cid,
                'name' => $company['name'] ?? null,
                'registrationNumber' => $company['registration_number'] ?? null,
                'documents' => $docs,
            ];
        }

        $result = [
            'contractsEnabled' => $companies !== [],
            'hasDocuments' => $totalDocuments > 0,
            'companies' => $companyPayload,
        ];

        // Contact card for the empty / pending state.
        $staffContacts = $this->staffContactRepo->findAll();
        if (!empty($staffContacts)) {
            $result['contact'] = [
                'name' => $staffContacts[0]['name'],
                'phone' => $staffContacts[0]['phone'],
                'email' => $staffContacts[0]['email'],
            ];
        }

        Response::success($result);
    }

    /**
     * Stream a single document. Authorisation: the document's company must belong to the
     * authenticated user. Used by the client portal preview/download.
     */
    public function downloadDocument(Request $request): void
    {
        $user = $request->getUser();
        $userId = (int) $user['id'];
        $documentId = (int) $request->param('id');

        $document = $this->documents->findById($documentId);
        if ($document === null) {
            throw new NotFoundException('Dokument nebyl nalezen');
        }

        $userCompanyIds = array_map(
            static fn ($c) => (int) $c['id'],
            $this->companyRepo->findByUserId($userId)
        );
        if (!in_array((int) $document['company_id'], $userCompanyIds, true)) {
            // Same 404 as a missing document — never reveal that someone else's id exists.
            throw new NotFoundException('Dokument nebyl nalezen');
        }

        $file = $this->documents->getFileForDownload($document);

        $asAttachment = $request->query('dl') === '1';
        Response::stream($file['content'], $file['mimeType'], $file['filename'], $asAttachment);
    }
}
