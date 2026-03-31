<?php

declare(strict_types=1);

namespace App\Exceptions;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Nenalezeno')
    {
        parent::__construct($message, 404);
    }
}
