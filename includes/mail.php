<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/mail.php';

function mail_debug_enabled(): bool
{
    return defined('MAIL_DEBUG') && MAIL_DEBUG;
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function mail_is_configured(): array
{
    if (trim(MAIL_SMTP_USER) === '' || trim(MAIL_SMTP_PASS) === '' || trim(MAIL_FROM_EMAIL) === '') {
        return [
            'ok' => false,
            'error' => 'Email is not configured. Set MAIL_SMTP_USER and MAIL_SMTP_PASS in config/mail.local.php.',
        ];
    }

    return ['ok' => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function mail_send_html(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): array
{
    $cfg = mail_is_configured();
    if (!$cfg['ok']) {
        return $cfg;
    }

    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email address.'];
    }

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8'));
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->Port = MAIL_SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_SMTP_USER;
        $mail->Password = MAIL_SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        // Common on local XAMPP when OpenSSL CA bundle is missing.
        if (mail_debug_enabled()) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->send();

        return ['ok' => true];
    } catch (MailerException $e) {
        $msg = 'Could not send email. Please try again later.';
        if (mail_debug_enabled()) {
            $detail = trim($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage());
            if ($detail !== '') {
                $msg = 'Email error: ' . $detail;
            }
        }

        return ['ok' => false, 'error' => $msg];
    }
}
