<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Config\Database;

class SettingsController extends Controller
{
    public function index(Request $request): void
    {
        $userId = $request->getUserId();
        $db = Database::getConnection();

        $stmt = $db->prepare('
            SELECT notification_email, notification_invoice, notification_attendance
            FROM user_settings
            WHERE user_id = :user_id
        ');

        $stmt->execute(['user_id' => $userId]);
        $settings = $stmt->fetch();

        // Return default settings if none exist
        if (!$settings) {
            $settings = [
                'notification_email' => true,
                'notification_invoice' => true,
                'notification_attendance' => true
            ];
        }

        Response::success([
            'notification_email' => (bool) $settings['notification_email'],
            'notification_invoice' => (bool) $settings['notification_invoice'],
            'notification_attendance' => (bool) $settings['notification_attendance']
        ]);
    }

    public function update(Request $request): void
    {
        $userId = $request->getUserId();
        $db = Database::getConnection();

        $data = $this->validate($request->getBody(), [
            'notification_email' => 'boolean',
            'notification_invoice' => 'boolean',
            'notification_attendance' => 'boolean'
        ]);

        // Check if settings exist
        $stmt = $db->prepare('SELECT id FROM user_settings WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $exists = $stmt->fetch();

        if ($exists) {
            // Build update query dynamically
            $fields = [];
            $params = ['user_id' => $userId];

            foreach (['notification_email', 'notification_invoice', 'notification_attendance'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = :{$field}";
                    $params[$field] = $data[$field] ? 1 : 0;
                }
            }

            if (!empty($fields)) {
                $fields[] = 'updated_at = NOW()';
                $sql = 'UPDATE user_settings SET ' . implode(', ', $fields) . ' WHERE user_id = :user_id';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
        } else {
            // Insert new settings
            $stmt = $db->prepare('
                INSERT INTO user_settings (
                    user_id, notification_email, notification_invoice, notification_attendance,
                    created_at, updated_at
                )
                VALUES (
                    :user_id, :notification_email, :notification_invoice, :notification_attendance,
                    NOW(), NOW()
                )
            ');

            $stmt->execute([
                'user_id' => $userId,
                'notification_email' => ($data['notification_email'] ?? true) ? 1 : 0,
                'notification_invoice' => ($data['notification_invoice'] ?? true) ? 1 : 0,
                'notification_attendance' => ($data['notification_attendance'] ?? true) ? 1 : 0
            ]);
        }

        Response::success(null, 'Nastavení bylo uloženo');
    }
}
