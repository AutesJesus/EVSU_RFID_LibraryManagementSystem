<?php

declare(strict_types=1);

require_once __DIR__ . '/mail.php';

const PATRON_OTP_TTL_SECONDS = 600;
const PATRON_OTP_RESEND_COOLDOWN_SECONDS = 60;

function auth_clear_patron_otp_pending(): void
{
    unset($_SESSION['patron_login_otp_pending']);
}

function auth_patron_otp_pending(): ?array
{
    $pending = $_SESSION['patron_login_otp_pending'] ?? null;
    if (!is_array($pending)) {
        return null;
    }

    $userId = (int) ($pending['user_id'] ?? 0);
    $expires = (int) ($pending['expires'] ?? 0);
    if ($userId <= 0 || $expires < time()) {
        auth_clear_patron_otp_pending();

        return null;
    }

    return $pending;
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: true, step: 'email_otp'}|array{ok: false, error: string}
 */
function auth_start_patron_email_otp(array $user): array
{
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'error' => 'No email is on file for this account. Ask the library admin to add your email.',
        ];
    }

    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['student', 'faculty', 'librarian'], true)) {
        return ['ok' => false, 'error' => 'This account cannot sign in here.'];
    }

    // Check if user has 2FA enabled
    $otpEnabled = isset($user['otp_enabled']) ? (int) $user['otp_enabled'] : 1;
    if ($otpEnabled !== 1) {
        return ['ok' => false, 'error' => '2FA is disabled for this account.'];
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    session_regenerate_id(true);
    $_SESSION['patron_login_otp_pending'] = [
        'user_id' => (int) $user['id'],
        'full_name' => (string) ($user['full_name'] ?? ''),
        'role' => $role,
        'email' => $email,
        'otp_hash' => $hash,
        'expires' => time() + PATRON_OTP_TTL_SECONDS,
        'last_sent' => time(),
    ];
    auth_clear_admin_rfid_pending();

    $send = auth_send_patron_otp_email($email, $code);
    if (!$send['ok']) {
        auth_clear_patron_otp_pending();

        return $send;
    }

    return ['ok' => true, 'step' => 'email_otp'];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function auth_resend_patron_email_otp(): array
{
    $pending = auth_patron_otp_pending();
    if ($pending === null) {
        return ['ok' => false, 'error' => 'Sign-in step expired. Please sign in again.'];
    }

    $lastSent = (int) ($pending['last_sent'] ?? 0);
    $wait = PATRON_OTP_RESEND_COOLDOWN_SECONDS - (time() - $lastSent);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Please wait ' . $wait . ' seconds before requesting a new code.'];
    }

    $code = (string) random_int(100000, 999999);
    $_SESSION['patron_login_otp_pending']['otp_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['patron_login_otp_pending']['expires'] = time() + PATRON_OTP_TTL_SECONDS;
    $_SESSION['patron_login_otp_pending']['last_sent'] = time();

    return auth_send_patron_otp_email((string) $pending['email'], $code);
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function auth_send_patron_otp_email(string $email, string $code): array
{
    $masked = auth_mask_email($email);
    $subject = 'Your EVSU RFID Library sign-in code';
    $html = '<p>Hello,</p>'
        . '<p>Your one-time sign-in code is:</p>'
        . '<p style="font-size:1.5rem;font-weight:bold;letter-spacing:0.2em;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 10 minutes. If you did not try to sign in, you can ignore this email.</p>'
        . '<p style="color:#666;font-size:0.9rem;">Sent to ' . htmlspecialchars($masked, ENT_QUOTES, 'UTF-8') . '</p>';
    $text = "Your EVSU RFID Library sign-in code is: {$code}\n\nIt expires in 10 minutes.";

    return mail_send_html($email, $subject, $html, $text);
}

function auth_mask_email(string $email): string
{
    $email = trim($email);
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    $len = strlen($local);
    if ($len <= 1) {
        $maskedLocal = '*';
    } elseif ($len === 2) {
        $maskedLocal = $local[0] . '*';
    } else {
        $maskedLocal = $local[0] . str_repeat('*', min(6, $len - 2)) . $local[$len - 1];
    }

    return $maskedLocal . '@' . $domain;
}

/**
 * @return array{ok: true, redirect: string}|array{ok: false, error: string}
 */
function auth_verify_patron_email_otp(string $codeRaw): array
{
    $pending = auth_patron_otp_pending();
    if ($pending === null) {
        return ['ok' => false, 'error' => 'Sign-in step expired. Please sign in again.'];
    }

    $code = preg_replace('/\D/', '', trim($codeRaw));
    if (strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    $hash = (string) ($pending['otp_hash'] ?? '');
    if ($hash === '' || !password_verify($code, $hash)) {
        return ['ok' => false, 'error' => 'Invalid or expired code. Try again or request a new code.'];
    }

    $role = (string) ($pending['role'] ?? '');
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $pending['user_id'];
    $_SESSION['user_full_name'] = (string) ($pending['full_name'] ?? '');
    $_SESSION['user_role'] = $role;
    auth_clear_patron_otp_pending();
    auth_clear_admin_rfid_pending();

    $redirect = $role === 'student' ? 'student/index.php' : 'faculty/index.php';

    return ['ok' => true, 'redirect' => $redirect];
}
