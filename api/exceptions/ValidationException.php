<?php

declare(strict_types=1);

namespace App\Exceptions;

class ValidationException extends ApiException
{
    private array $errors;

    public function __construct(string $message = 'Validace selhala', array $errors = [])
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
