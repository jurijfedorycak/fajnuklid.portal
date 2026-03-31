<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EmployeeRepository;
use App\Repositories\ObjectRepository;
use App\Repositories\IcoRepository;

class PersonnelService
{
    private EmployeeRepository $employeeRepository;
    private ObjectRepository $objectRepository;
    private IcoRepository $icoRepository;

    public function __construct()
    {
        $this->employeeRepository = new EmployeeRepository();
        $this->objectRepository = new ObjectRepository();
        $this->icoRepository = new IcoRepository();
    }

    public function getPersonnelForClient(int $clientId, ?string $icoFilter = null): array
    {
        $icos = $this->icoRepository->findByClientId($clientId);

        if (empty($icos)) {
            return [];
        }

        // Filter by specific IČO if provided
        if ($icoFilter) {
            $icos = array_filter($icos, fn($ico) => $ico['ico'] === $icoFilter);
        }

        $result = [];

        foreach ($icos as $ico) {
            $objects = $this->objectRepository->findByIco($ico['ico']);

            $icoData = [
                'ico' => $ico['ico'],
                'name' => $ico['name'],
                'objects' => []
            ];

            foreach ($objects as $object) {
                $employees = $this->employeeRepository->findByObjectId($object['id'], true);

                $icoData['objects'][] = [
                    'id' => $object['id'],
                    'name' => $object['name'],
                    'address' => $object['address'],
                    'employees' => array_map(function ($employee) {
                        return [
                            'id' => $employee['id'],
                            'first_name' => $employee['first_name'],
                            'last_name' => $employee['last_name'],
                            'position' => $employee['position'],
                            'photo_url' => $employee['photo_url'],
                            'phone' => $employee['phone'],
                            'email' => $employee['email']
                        ];
                    }, $employees)
                ];
            }

            $result[] = $icoData;
        }

        return $result;
    }
}
