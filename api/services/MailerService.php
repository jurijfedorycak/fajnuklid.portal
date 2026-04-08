<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Minimal mailer that uses PHP's built-in mail() function with SMTP env config
 * read for documentation. Production deployments should plug in PHPMailer here
 * if SMTP relaying is required.
 */
class MailerService
{
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'no-reply@fajnuklid.cz';
        $this->fromName = getenv('SMTP_FROM_NAME') ?: 'Fajn Úklid Portál';
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if ($to === '') {
            return false;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $encodedFromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: FajnUklidPortal',
        ];

        return @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers));
    }
}
