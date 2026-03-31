<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\NotFoundException;
use App\Repositories\EmployeeRepository;

class EmployeeController extends Controller
{
    private EmployeeRepository $employeeRepository;

    public function __construct()
    {
        $this->employeeRepository = new EmployeeRepository();
    }

    public function index(Request $request): void
    {
        $pagination = $this->getPagination($request);
        $search = $request->query('search');

        $employees = $this->employeeRepository->findAll(
            $pagination['offset'],
            $pagination['per_page'],
            $search
        );

        $total = $this->employeeRepository->count($search);

        // Get assigned objects for each employee
        foreach ($employees as &$employee) {
            $employee['assigned_objects'] = $this->employeeRepository->getAssignedObjects($employee['id']);
        }

        Response::paginated(
            $employees,
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    public function show(Request $request): void
    {
        $id = (int) $request->param('id');

        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nenalezen');
        }

        $employee['assigned_objects'] = $this->employeeRepository->getAssignedObjects($id);

        Response::success($employee);
    }

    public function store(Request $request): void
    {
        $data = $this->validate($request->getBody(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'email|max:255',
            'phone' => 'string|max:20',
            'position' => 'string|max:100',
            'photo_url' => 'string|max:500',
            'show_name' => 'boolean',
            'show_photo' => 'boolean',
            'show_phone' => 'boolean',
            'show_email' => 'boolean',
            'active' => 'boolean'
        ]);

        $id = $this->employeeRepository->create($data);

        $employee = $this->employeeRepository->findById($id);
        $employee['assigned_objects'] = [];

        Response::created($employee, 'Zaměstnanec byl vytvořen');
    }

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');

        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nenalezen');
        }

        $data = $this->validate($request->getBody(), [
            'first_name' => 'string|max:100',
            'last_name' => 'string|max:100',
            'email' => 'email|max:255',
            'phone' => 'string|max:20',
            'position' => 'string|max:100',
            'photo_url' => 'string|max:500',
            'show_name' => 'boolean',
            'show_photo' => 'boolean',
            'show_phone' => 'boolean',
            'show_email' => 'boolean',
            'active' => 'boolean'
        ]);

        $this->employeeRepository->update($id, $data);

        $employee = $this->employeeRepository->findById($id);
        $employee['assigned_objects'] = $this->employeeRepository->getAssignedObjects($id);

        Response::success($employee, 'Zaměstnanec byl aktualizován');
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->param('id');

        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nenalezen');
        }

        $this->employeeRepository->delete($id);

        Response::noContent();
    }

    public function assignObjects(Request $request): void
    {
        $id = (int) $request->param('id');

        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nenalezen');
        }

        $data = $this->validate($request->getBody(), [
            'object_ids' => 'required|array'
        ]);

        foreach ($data['object_ids'] as $objectId) {
            $this->employeeRepository->assignToObject($id, (int) $objectId);
        }

        $employee['assigned_objects'] = $this->employeeRepository->getAssignedObjects($id);

        Response::success($employee, 'Objekty byly přiřazeny');
    }

    public function unassignObject(Request $request): void
    {
        $id = (int) $request->param('id');
        $objectId = (int) $request->param('objectId');

        $employee = $this->employeeRepository->findById($id);

        if (!$employee) {
            throw new NotFoundException('Zaměstnanec nenalezen');
        }

        $this->employeeRepository->unassignFromObject($id, $objectId);

        Response::success(null, 'Objekt byl odebrán');
    }
}
