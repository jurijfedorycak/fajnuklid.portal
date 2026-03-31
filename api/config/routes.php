<?php

declare(strict_types=1);

use App\Core\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

/** @var Router $router */

// Apply CORS middleware to all routes
$router->addGlobalMiddleware(CorsMiddleware::class);

// Public routes - Authentication
$router->post('/auth/login', 'AuthController@login');
$router->post('/auth/forgot-password', 'AuthController@forgotPassword');
$router->post('/auth/reset-password', 'AuthController@resetPassword');

// Protected routes - require authentication
$router->group(['middleware' => [AuthMiddleware::class]], function (Router $router) {
    // Auth
    $router->get('/auth/me', 'AuthController@me');
    $router->post('/auth/change-password', 'AuthController@changePassword');
    $router->post('/auth/logout', 'AuthController@logout');

    // Invoices
    $router->get('/invoices', 'InvoiceController@index');
    $router->get('/invoices/{id}/pdf', 'InvoiceController@downloadPdf');

    // Personnel
    $router->get('/personnel', 'PersonnelController@index');

    // Contracts
    $router->get('/contracts', 'ContractController@index');
    $router->get('/contracts/{ico}/pdf', 'ContractController@downloadPdf');

    // Attendance (FreshQR placeholder)
    $router->get('/attendance', 'AttendanceController@index');

    // Contact information
    $router->get('/contact', 'ContactController@index');

    // User settings
    $router->get('/settings', 'SettingsController@index');
    $router->put('/settings', 'SettingsController@update');
});

// Admin routes - require authentication + admin privileges
$router->group(['middleware' => [AuthMiddleware::class, AdminMiddleware::class], 'prefix' => '/admin'], function (Router $router) {
    // Clients management
    $router->get('/clients', 'Admin\ClientController@index');
    $router->get('/clients/{id}', 'Admin\ClientController@show');
    $router->post('/clients', 'Admin\ClientController@store');
    $router->put('/clients/{id}', 'Admin\ClientController@update');
    $router->delete('/clients/{id}', 'Admin\ClientController@destroy');

    // IČO management per client
    $router->get('/clients/{clientId}/icos', 'Admin\IcoController@index');
    $router->get('/clients/{clientId}/icos/{id}', 'Admin\IcoController@show');
    $router->post('/clients/{clientId}/icos', 'Admin\IcoController@store');
    $router->put('/clients/{clientId}/icos/{id}', 'Admin\IcoController@update');
    $router->delete('/clients/{clientId}/icos/{id}', 'Admin\IcoController@destroy');

    // Objects management
    $router->get('/objects', 'Admin\ObjectController@index');
    $router->get('/objects/{id}', 'Admin\ObjectController@show');
    $router->post('/objects', 'Admin\ObjectController@store');
    $router->put('/objects/{id}', 'Admin\ObjectController@update');
    $router->delete('/objects/{id}', 'Admin\ObjectController@destroy');

    // Employees management
    $router->get('/employees', 'Admin\EmployeeController@index');
    $router->get('/employees/{id}', 'Admin\EmployeeController@show');
    $router->post('/employees', 'Admin\EmployeeController@store');
    $router->put('/employees/{id}', 'Admin\EmployeeController@update');
    $router->delete('/employees/{id}', 'Admin\EmployeeController@destroy');

    // Employee object assignments
    $router->post('/employees/{id}/assignments', 'Admin\EmployeeController@assignObjects');
    $router->delete('/employees/{id}/assignments/{objectId}', 'Admin\EmployeeController@unassignObject');
});
