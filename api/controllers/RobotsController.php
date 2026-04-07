<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;

class RobotsController extends Controller
{
    public function index(): void
    {
        $env = Config::get('APP_ENV', 'development');
        $isProduction = $env === 'production';

        header('Content-Type: text/plain');

        if ($isProduction) {
            echo "User-agent: *\nAllow: /\n";
        } else {
            echo "User-agent: *\nDisallow: /\n";
        }
        exit;
    }
}
