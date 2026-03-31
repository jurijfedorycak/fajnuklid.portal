<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Repositories\IcoRepository;

class ContractService
{
    private IcoRepository $icoRepository;

    public function __construct()
    {
        $this->icoRepository = new IcoRepository();
    }

    public function getContractsForClient(int $clientId): array
    {
        $icos = $this->icoRepository->findByClientId($clientId);

        return array_map(function ($ico) {
            return [
                'id' => $ico['id'],
                'ico' => $ico['ico'],
                'name' => $ico['name'],
                'address' => $ico['address'],
                'contract_start_date' => $ico['contract_start_date'],
                'contract_end_date' => $ico['contract_end_date'],
                'has_contract_pdf' => !empty($ico['contract_pdf_path'])
            ];
        }, $icos);
    }

    public function getContractPdf(int $clientId, string $ico): array
    {
        $icoData = $this->icoRepository->findByIco($ico);

        if (!$icoData) {
            throw new NotFoundException('Smlouva nenalezena');
        }

        // Verify that this IČO belongs to the client
        $clientIcos = $this->icoRepository->findByClientId($clientId);
        $clientIcoNumbers = array_column($clientIcos, 'ico');

        if (!in_array($ico, $clientIcoNumbers, true)) {
            throw new NotFoundException('Smlouva nenalezena');
        }

        if (empty($icoData['contract_pdf_path'])) {
            throw new NotFoundException('PDF smlouvy není k dispozici');
        }

        // Validate path is within allowed contracts directory to prevent path traversal
        $basePath = realpath(__DIR__ . '/../storage/contracts');
        $pdfPath = realpath($icoData['contract_pdf_path']);

        if (!$pdfPath || !$basePath || strpos($pdfPath, $basePath) !== 0) {
            throw new NotFoundException('PDF soubor nenalezen');
        }

        if (!file_exists($pdfPath)) {
            throw new NotFoundException('PDF soubor nenalezen');
        }

        $pdfContent = file_get_contents($pdfPath);

        return [
            'content' => $pdfContent,
            'filename' => "smlouva-{$ico}.pdf"
        ];
    }
}
