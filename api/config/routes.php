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
$router->post('/auth/login', 'AuthController@login');

// Protected routes - require authentication
$router->group(['middleware' => [AuthMiddleware::class]], function (Router $router) {
    // Auth
    $router->post('/auth/logout', 'AuthController@logout');
    $router->get('/auth/me', 'AuthController@me');

    // Dashboard - single endpoint for all dashboard data
    $router->get('/dashboard', 'DashboardController@index');

    // Personnel - staff grouped by location
    $router->get('/personnel', 'PersonnelController@index');

    // Contract - contract info + download
    $router->get('/contract', 'ContractController@index');
    $router->get('/contract/download', 'ContractController@download');

    // Contacts - Fajnuklid staff contacts
    $router->get('/contacts', 'ContactController@index');

    // Attendance - cleaning calendar
    $router->get('/attendance', 'AttendanceController@index');

    // Invoices - client invoices
    $router->get('/invoices', 'InvoiceController@index');

    // Settings - user preferences
    $router->get('/settings', 'SettingsController@index');
    $router->put('/settings', 'SettingsController@update');
    $router->post('/settings/password', 'SettingsController@changePassword');
});

// Admin routes - require authentication + admin privileges
$router->group(['middleware' => [AuthMiddleware::class, AdminMiddleware::class], 'prefix' => '/admin'], function (Router $router) {
    // Stats
    $router->get('/stats', 'AdminController@stats');

    // File upload
    $router->post('/upload', 'AdminController@uploadFile');

    // Clients
    $router->get('/clients', 'AdminController@listClients');
    $router->get('/clients/{id}', 'AdminController@getClient');
    $router->post('/clients', 'AdminController@createClient');
    $router->put('/clients/{id}', 'AdminController@updateClient');
    $router->delete('/clients/{id}', 'AdminController@deleteClient');

    // Employees
    $router->get('/employees', 'AdminController@listEmployees');
    $router->get('/employees/{id}', 'AdminController@getEmployee');
    $router->post('/employees', 'AdminController@createEmployee');
    $router->put('/employees', 'AdminController@saveEmployees');
    $router->put('/employees/{id}', 'AdminController@updateEmployee');
    $router->delete('/employees/{id}', 'AdminController@deleteEmployee');
});
