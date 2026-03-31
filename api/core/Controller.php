<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ValidationException;
use App\Helpers\Validator;

abstract class Controller
{
    protected function validate(array $data, array $rules): array
    {
        $validator = new Validator($data, $rules);

        if (!$validator->validate()) {
            throw new ValidationException('Validace selhala', $validator->getErrors());
        }

        return $validator->getValidated();
    }

    protected function getPagination(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset
        ];
    }
}
