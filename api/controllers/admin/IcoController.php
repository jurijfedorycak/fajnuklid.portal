<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\NotFoundException;
use App\Repositories\IcoRepository;
use App\Repositories\ClientRepository;

class IcoController extends Controller
{
    private IcoRepository $icoRepository;
    private ClientRepository $clientRepository;

    public function __construct()
    {
        $this->icoRepository = new IcoRepository();
        $this->clientRepository = new ClientRepository();
    }

    public function index(Request $request): void
    {
        $clientId = (int) $request->param('clientId');

        $client = $this->clientRepository->findById($clientId);

        if (!$client) {
            throw new NotFoundException('Klient nenalezen');
        }

        $icos = $this->icoRepository->findByClientId($clientId);

        Response::success($icos);
    }

    public function show(Request $request): void
    {
        $clientId = (int) $request->param('clientId');
        $id = (int) $request->param('id');

        $ico = $this->icoRepository->findById($id);

        if (!$ico || $ico['client_id'] !== $clientId) {
            throw new NotFoundException('IČO nenalezeno');
        }

        Response::success($ico);
    }

    public function store(Request $request): void
    {
        $clientId = (int) $request->param('clientId');

        $client = $this->clientRepository->findById($clientId);

        if (!$client) {
            throw new NotFoundException('Klient nenalezen');
        }

        $data = $this->validate($request->getBody(), [
            'ico' => 'required|ico',
            'name' => 'required|string|max:255',
            'address' => 'string|max:500',
            'contract_start_date' => 'string',
            'contract_end_date' => 'string',
            'contract_pdf_path' => 'string|max:500'
        ]);

        $data['client_id'] = $clientId;

        $id = $this->icoRepository->create($data);

        $ico = $this->icoRepository->findById($id);

        Response::created($ico, 'IČO bylo vytvořeno');
    }

    public function update(Request $request): void
    {
        $clientId = (int) $request->param('clientId');
        $id = (int) $request->param('id');

        $ico = $this->icoRepository->findById($id);

        if (!$ico || $ico['client_id'] !== $clientId) {
            throw new NotFoundException('IČO nenalezeno');
        }

        $data = $this->validate($request->getBody(), [
            'ico' => 'ico',
            'name' => 'string|max:255',
            'address' => 'string|max:500',
            'contract_start_date' => 'string',
            'contract_end_date' => 'string',
            'contract_pdf_path' => 'string|max:500'
        ]);

        $this->icoRepository->update($id, $data);

        $ico = $this->icoRepository->findById($id);

        Response::success($ico, 'IČO bylo aktualizováno');
    }

    public function destroy(Request $request): void
    {
        $clientId = (int) $request->param('clientId');
        $id = (int) $request->param('id');

        $ico = $this->icoRepository->findById($id);

        if (!$ico || $ico['client_id'] !== $clientId) {
            throw new NotFoundException('IČO nenalezeno');
        }

        $this->icoRepository->delete($id);

        Response::noContent();
    }
}
