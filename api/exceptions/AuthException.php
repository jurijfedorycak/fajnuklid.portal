<?php

declare(strict_types=1);

namespace App\Exceptions;

class AuthException extends ApiException
{
    public function __construct(string $message = 'Přístup odepřen')
    {
        parent::__construct($message, 401);
    }
}
