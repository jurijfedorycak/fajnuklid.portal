<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Config\Database;

class ContactController extends Controller
{
    public function index(Request $request): void
    {
        $db = Database::getConnection();

        // Get company info
        $stmt = $db->query('SELECT * FROM company_info LIMIT 1');
        $companyInfo = $stmt->fetch();

        // Get contacts
        $stmt = $db->query('SELECT * FROM fajnuklid_contacts WHERE active = 1 ORDER BY sort_order ASC');
        $contacts = $stmt->fetchAll();

        Response::success([
            'company' => $companyInfo ? [
                'name' => $companyInfo['name'],
                'ico' => $companyInfo['ico'],
                'dic' => $companyInfo['dic'],
                'address' => $companyInfo['address'],
                'phone' => $companyInfo['phone'],
                'email' => $companyInfo['email'],
                'website' => $companyInfo['website']
            ] : null,
            'contacts' => array_map(function ($contact) {
                return [
                    'id' => $contact['id'],
                    'name' => $contact['name'],
                    'position' => $contact['position'],
                    'phone' => $contact['phone'],
                    'email' => $contact['email'],
                    'photo_url' => $contact['photo_url']
                ];
            }, $contacts)
        ]);
    }
}
