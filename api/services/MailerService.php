<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP-backed mailer. When SMTP_HOST is configured the message is relayed via
 * PHPMailer; otherwise we fall back to PHP's built-in mail() so local dev still
 * works without a relay. Delivery failures are logged, never thrown — callers
 * (e.g. MaintenanceRequestService) must not block the HTTP response when the
 * SMTP relay is temporarily unavailable.
 */
class MailerService
{
    private string $fromEmail;
    private string $fromName;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUsername;
    private string $smtpPassword;
    private string $smtpEncryption;

    public function __construct()
    {
        $this->fromEmail = Config::get('SMTP_FROM_EMAIL') ?: 'no-reply@fajnuklid.cz';
        $this->fromName = Config::get('SMTP_FROM_NAME') ?: 'Fajn Úklid Portál';
        $this->smtpHost = (string) (Config::get('SMTP_HOST') ?? '');
        $this->smtpPort = Config::getInt('SMTP_PORT', 587);
        $this->smtpUsername = (string) (Config::get('SMTP_USERNAME') ?? '');
        $this->smtpPassword = (string) (Config::get('SMTP_PASSWORD') ?? '');
        $this->smtpEncryption = strtolower((string) (Config::get('SMTP_ENCRYPTION') ?? 'tls'));
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if (trim($to) === '') {
            return false;
        }

        if ($this->smtpHost !== '' && $this->smtpHost !== 'smtp.example.com') {
            return $this->sendViaSmtp($to, $subject, $htmlBody);
        }

        return $this->sendViaMailFunction($to, $subject, $htmlBody);
    }

    private function sendViaSmtp(string $to, string $subject, string $htmlBody): bool
    {
        $mailer = null;

        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $this->smtpHost;
            $mailer->Port = $this->smtpPort;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Encoding = PHPMailer::ENCODING_BASE64;

            if ($this->smtpUsername !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $this->smtpUsername;
                $mailer->Password = $this->smtpPassword;
            }

            if ($this->smtpEncryption === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->smtpEncryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            $mailer->setFrom($this->fromEmail, $this->fromName);
            $mailer->addReplyTo($this->fromEmail, $this->fromName);
            $mailer->addAddress($to);

            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

            return $mailer->send();
        } catch (PHPMailerException $e) {
            $detail = $mailer !== null && $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : $e->getMessage();
            error_log(sprintf('SMTP send to %s failed: %s', $to, $detail));
            return false;
        } catch (\Throwable $e) {
            error_log(sprintf('SMTP send to %s failed: %s', $to, $e->getMessage()));
            return false;
        }
    }

    private function sendViaMailFunction(string $to, string $subject, string $htmlBody): bool
    {
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
