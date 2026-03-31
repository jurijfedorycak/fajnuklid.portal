<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Exceptions\AuthException;

class AdminMiddleware
{
    public function handle(Request $request): void
    {
        if (!$request->isAdmin()) {
            throw new AuthException('Přístup odmítnut. Vyžadována administrátorská práva.');
        }
    }
}
