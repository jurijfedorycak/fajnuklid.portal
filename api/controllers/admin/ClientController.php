<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\NotFoundException;
use App\Repositories\ClientRepository;

class ClientController extends Controller
{
    private ClientRepository $clientRepository;

    public function __construct()
    {
        $this->clientRepository = new ClientRepository();
    }

    public function index(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $clients = $this->clientRepository->findAll(
            $pagination['offset'],
            $pagination['per_page'],
            $search
        );

        $total = $this->clientRepository->count($search);

        Response::paginated(
            $clients,
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    public function show(Request $request): void
    {
        $id = (int) $request->param('id');

        $client = $this->clientRepository->findById($id);

        if (!$client) {
            throw new NotFoundException('Klient nenalezen');
        }

        Response::success($client);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'client_id' => 'required|string|max:50',
            'display_name' => 'required|string|max:255',
            'active' => 'boolean'
        ]);

        $id = $this->clientRepository->create($data);

        $client = $this->clientRepository->findById($id);

        Response::created($client, 'Klient byl vytvořen');
    }

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');

        $client = $this->clientRepository->findById($id);

        if (!$client) {
            throw new NotFoundException('Klient nenalezen');
        }

        $data = $this->validate($request->getBody(), [
            'client_id' => 'string|max:50',
            'display_name' => 'string|max:255',
            'active' => 'boolean'
        ]);

        $this->clientRepository->update($id, $data);

        $client = $this->clientRepository->findById($id);

        Response::success($client, 'Klient byl aktualizován');
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->param('id');

        $client = $this->clientRepository->findById($id);

        if (!$client) {
            throw new NotFoundException('Klient nenalezen');
        }

        $this->clientRepository->delete($id);

        Response::noContent();
    }
}
