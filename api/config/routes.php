<?php

declare(strict_types=1);

use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

/** @var Router $router */

// Apply CORS middleware to all routes
$router->addGlobalMiddleware(CorsMiddleware::class);

// Public routes
// $router->post('/auth/login', 'AuthController@login');

// Protected routes - require authentication
$router->group(['middleware' => [AuthMiddleware::class]], function (Router $router) {
    // Add routes here
});

// Admin routes - require authentication + admin privileges
$router->group(['middleware' => [AuthMiddleware::class, AdminMiddleware::class], 'prefix' => '/admin'], function (Router $router) {
    // Add routes here
});
