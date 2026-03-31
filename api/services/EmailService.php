<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;

class EmailService
{
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->smtpHost = Config::get('SMTP_HOST', '');
        $this->smtpPort = Config::getInt('SMTP_PORT', 587);
        $this->smtpUsername = Config::get('SMTP_USERNAME', '');
        $this->smtpPassword = Config::get('SMTP_PASSWORD', '');
        $this->fromEmail = Config::get('SMTP_FROM_EMAIL', 'noreply@fajnuklid.cz');
        $this->fromName = Config::get('SMTP_FROM_NAME', 'Fajnuklid Portal');
    }

    public function sendPasswordResetEmail(string $to, string $token): bool
    {
        $frontendUrl = Config::get('FRONTEND_URL', 'http://localhost:5173');
        $resetUrl = "{$frontendUrl}/reset-password?token={$token}";

        $subject = 'Obnovení hesla - Fajnuklid Portal';

        $body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4CAF50;
            color: white !important;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }
        .footer { margin-top: 30px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Obnovení hesla</h2>
        <p>Dobrý den,</p>
        <p>obdrželi jsme žádost o obnovení hesla k vašemu účtu v portálu Fajnuklid.</p>
        <p>Pro nastavení nového hesla klikněte na tlačítko níže:</p>
        <a href="{$resetUrl}" class="button">Nastavit nové heslo</a>
        <p>Odkaz je platný 60 minut.</p>
        <p>Pokud jste o obnovení hesla nežádali, tento e-mail můžete ignorovat.</p>
        <div class="footer">
            <p>S pozdravem,<br>Tým Fajnuklid</p>
        </div>
    </div>
</body>
</html>
HTML;

        return $this->send($to, $subject, $body);
    }

    public function send(string $to, string $subject, string $body): bool
    {
        // If SMTP is not configured, log instead of sending
        if (empty($this->smtpHost)) {
            error_log("Email would be sent to: {$to}, Subject: {$subject}");
            return true;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            "From: {$this->fromName} <{$this->fromEmail}>",
            "Reply-To: {$this->fromEmail}",
            'X-Mailer: PHP/' . phpversion()
        ];

        // For production, use proper SMTP library like PHPMailer
        // This is a simplified implementation using mail()
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
