<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthException;
use App\Services\IDokladService;

class CronController extends Controller
{
    private IDokladService $idokladService;

    public function __construct()
    {
        $this->idokladService = new IDokladService();
    }

    public function syncIdoklad(Request $request): void
    {
        $this->assertValidCronToken($request);

        $result = $this->idokladService->syncAllEnabledCompanies();

        Response::success($result);
    }

    private function assertValidCronToken(Request $request): void
    {
        $expected = (string) Config::get('IDOKLAD_CRON_TOKEN', '');

        if ($expected === '') {
            // Refuse to authorize when no token is configured — never run unguarded.
            throw new AuthException('Cron token není nakonfigurován');
        }

        $headerToken = (string) ($request->getHeader('X-Cron-Token') ?? '');
        $queryToken = (string) $request->query('token', '');
        $provided = $headerToken !== '' ? $headerToken : $queryToken;

        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new AuthException('Neplatný cron token');
        }
    }
}
