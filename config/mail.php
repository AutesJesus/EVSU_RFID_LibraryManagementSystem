<?php

declare(strict_types=1);

/** Brevo SMTP relay defaults. Override secrets in config/mail.local.php */
const MAIL_SMTP_HOST = 'smtp-relay.brevo.com';
const MAIL_SMTP_PORT = 587;
const MAIL_SMTP_SECURE = 'tls';
const MAIL_FROM_NAME = 'EVSU RFID Library';

$mailLocal = __DIR__ . '/mail.local.php';
if (is_file($mailLocal)) {
    require_once $mailLocal;
}

/** Credentials: set in mail.local.php (loaded above) */
if (!defined('MAIL_SMTP_USER')) {
    define('MAIL_SMTP_USER', '');
}
if (!defined('MAIL_SMTP_PASS')) {
    define('MAIL_SMTP_PASS', '');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', MAIL_SMTP_USER);
}
