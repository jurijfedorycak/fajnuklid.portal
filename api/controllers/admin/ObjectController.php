<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\NotFoundException;
use App\Repositories\ObjectRepository;

class ObjectController extends Controller
{
    private ObjectRepository $objectRepository;

    public function __construct()
    {
        $this->objectRepository = new ObjectRepository();
    }

    public function index(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $objects = $this->objectRepository->findAll(
            $pagination['offset'],
            $pagination['per_page'],
            $search
        );

        $total = $this->objectRepository->count($search);

        Response::paginated(
            $objects,
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    public function show(Request $request): void
    {
        $id = (int) $request->param('id');

        $object = $this->objectRepository->findById($id);

        if (!$object) {
            throw new NotFoundException('Objekt nenalezen');
        }

        Response::success($object);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'ico_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'address' => 'string|max:500',
            'latitude' => 'numeric',
            'longitude' => 'numeric'
        ]);

        $id = $this->objectRepository->create($data);

        $object = $this->objectRepository->findById($id);

        Response::created($object, 'Objekt byl vytvořen');
    }

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');

        $object = $this->objectRepository->findById($id);

        if (!$object) {
            throw new NotFoundException('Objekt nenalezen');
        }

        $data = $this->validate($request->getBody(), [
            'ico_id' => 'integer',
            'name' => 'string|max:255',
            'address' => 'string|max:500',
            'latitude' => 'numeric',
            'longitude' => 'numeric'
        ]);

        $this->objectRepository->update($id, $data);

        $object = $this->objectRepository->findById($id);

        Response::success($object, 'Objekt byl aktualizován');
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->param('id');

        $object = $this->objectRepository->findById($id);

        if (!$object) {
            throw new NotFoundException('Objekt nenalezen');
        }

        $this->objectRepository->delete($id);

        Response::noContent();
    }
}
