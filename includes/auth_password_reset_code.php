<?php
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

// Code-based password reset (NO reset link).
const PASSWORD_RESET_CODE_TTL_SECONDS = 900; // 15 minutes
const PASSWORD_RESET_CODE_RESEND_COOLDOWN_SECONDS = 30;
const PASSWORD_RESET_CODE_LENGTH = 6;

function auth_password_reset_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function auth_password_reset_normalize_code(string $codeRaw): string
{
    return (string) preg_replace('/\D/', '', trim($codeRaw));
}

/**
 * @return array{ok: true, email: string}|array{ok: false, error: string}
 */
function auth_password_reset_start_code(PDO $pdo, string $email): array
{
    $emailNorm = auth_password_reset_normalize_email($email);
    if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, email, full_name
         FROM users
         WHERE status = \'active\'
           AND email IS NOT NULL
           AND LOWER(TRIM(email)) = LOWER(TRIM(:email))
         LIMIT 1'
    );
    $stmt->execute(['email' => $emailNorm]);
    $user = $stmt->fetch();
    if ($user === false) {
        return ['ok' => false, 'error' => 'No active account found for that email.'];
    }

    // Session-based cooldown (avoids PHP/MySQL timezone mismatch on requested_at).
    $userId = (int) $user['id'];
    $lastSent = (int) ($_SESSION['pw_reset_last_sent'][$userId] ?? 0);
    $wait = PASSWORD_RESET_CODE_RESEND_COOLDOWN_SECONDS - (time() - $lastSent);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Please wait ' . $wait . ' seconds before requesting a new code.'];
    }

    $min = 10 ** (PASSWORD_RESET_CODE_LENGTH - 1);
    $max = (10 ** PASSWORD_RESET_CODE_LENGTH) - 1;
    $code = (string) random_int($min, $max);

    $subject = 'EVSU RFID Library password reset code';
    $html = '<p>Hello,</p>'
        . '<p>Your password reset verification code is:</p>'
        . '<p style="font-size:1.6rem;font-weight:bold;letter-spacing:0.2em;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 15 minutes. If you did not request it, you can ignore this email.</p>';
    $text = "Your EVSU RFID Library password reset verification code is: {$code}\n\nIt expires in 15 minutes.";

    $send = mail_send_html((string) $user['email'], $subject, $html, $text);
    if (!$send['ok']) {
        return $send;
    }

    // Reuse existing password_resets table (token_hash becomes a code hash).
    $tokenHash = hash('sha256', $code);
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . PASSWORD_RESET_CODE_TTL_SECONDS . ' seconds');

    $ins = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip, user_agent)
         VALUES (:user_id, :token_hash, :expires_at, :request_ip, :user_agent)'
    );
    $ins->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        'request_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    if (!isset($_SESSION['pw_reset_last_sent']) || !is_array($_SESSION['pw_reset_last_sent'])) {
        $_SESSION['pw_reset_last_sent'] = [];
    }
    $_SESSION['pw_reset_last_sent'][$userId] = time();

    return ['ok' => true, 'email' => (string) $user['email']];
}

/**
 * Verify the code for an email and store a short-lived session allowance.
 *
 * @return array{ok:true}|array{ok:false,error:string}
 */
function auth_password_reset_verify_code(PDO $pdo, string $email, string $codeRaw): array
{
    $emailNorm = auth_password_reset_normalize_email($email);
    $code = auth_password_reset_normalize_code($codeRaw);

    if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if (strlen($code) !== PASSWORD_RESET_CODE_LENGTH) {
        return ['ok' => false, 'error' => 'Enter the ' . PASSWORD_RESET_CODE_LENGTH . '-digit code.'];
    }

    $uStmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE status = \'active\'
           AND email IS NOT NULL
           AND LOWER(TRIM(email)) = LOWER(TRIM(:email))
         LIMIT 1'
    );
    $uStmt->execute(['email' => $emailNorm]);
    $user = $uStmt->fetch();
    if ($user === false) {
        return ['ok' => false, 'error' => 'Account not found.'];
    }

    $tokenHash = hash('sha256', $code);
    $nowSql = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $sel = $pdo->prepare(
        'SELECT id
         FROM password_resets
         WHERE user_id = :user_id
           AND token_hash = :token_hash
           AND used_at IS NULL
           AND expires_at >= :now
         ORDER BY requested_at DESC
         LIMIT 1'
    );
    $sel->execute([
        'user_id' => (int) $user['id'],
        'token_hash' => $tokenHash,
        'now' => $nowSql,
    ]);
    $row = $sel->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    // Allow password update for a short window without re-sending the code.
    if (!isset($_SESSION['pw_reset_verified']) || !is_array($_SESSION['pw_reset_verified'])) {
        $_SESSION['pw_reset_verified'] = [];
    }
    $_SESSION['pw_reset_verified'] = [
        'user_id' => (int) $user['id'],
        'reset_id' => (int) $row['id'],
        'email' => $emailNorm,
        'expires' => time() + 600, // 10 minutes to set new password after verification
    ];

    return ['ok' => true];
}

/**
 * @return array{ok:true}|array{ok:false,error:string}
 */
function auth_password_reset_verify_and_update(
    PDO $pdo,
    string $email,
    string $codeRaw,
    string $newPassword,
    string $confirmPassword
): array {
    // If the user already verified the code, prefer the session allowance.
    $verified = $_SESSION['pw_reset_verified'] ?? null;
    if (is_array($verified)) {
        $exp = (int) ($verified['expires'] ?? 0);
        $uid = (int) ($verified['user_id'] ?? 0);
        $rid = (int) ($verified['reset_id'] ?? 0);
        $em = (string) ($verified['email'] ?? '');
        $emailNorm = auth_password_reset_normalize_email($email);

        if ($uid > 0 && $rid > 0 && $exp >= time() && $em !== '' && hash_equals($em, $emailNorm)) {
            if (strlen($newPassword) < 8) {
                return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
            }
            if (!preg_match('/[A-Z]/', $newPassword)) {
                return ['ok' => false, 'error' => 'Password must contain at least one uppercase letter.'];
            }
            if (!preg_match('/[0-9]/', $newPassword)) {
                return ['ok' => false, 'error' => 'Password must contain at least one number.'];
            }
            if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $newPassword)) {
                return ['ok' => false, 'error' => 'Password must contain at least one symbol.'];
            }
            if ($newPassword !== $confirmPassword) {
                return ['ok' => false, 'error' => 'Password confirmation does not match.'];
            }

            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare('UPDATE users SET password = :pw WHERE id = :id');
                $upd->execute([
                    'pw' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $uid,
                ]);

                $mark = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id AND user_id = :user_id AND used_at IS NULL');
                $mark->execute(['id' => $rid, 'user_id' => $uid]);
                if ($mark->rowCount() < 1) {
                    $pdo->rollBack();
                    unset($_SESSION['pw_reset_verified']);
                    return ['ok' => false, 'error' => 'Verification expired. Please request a new code.'];
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            unset($_SESSION['pw_reset_verified']);
            return ['ok' => true];
        }
    }

    $emailNorm = auth_password_reset_normalize_email($email);
    $code = auth_password_reset_normalize_code($codeRaw);

    if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid request. Please request a new code.'];
    }
    if (strlen($code) !== PASSWORD_RESET_CODE_LENGTH) {
        return ['ok' => false, 'error' => 'Enter the ' . PASSWORD_RESET_CODE_LENGTH . '-digit code.' ];
    }
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.' ];
    }
    if (!preg_match('/[A-Z]/', $newPassword)) {
        return ['ok' => false, 'error' => 'Password must contain at least one uppercase letter.' ];
    }
    if (!preg_match('/[0-9]/', $newPassword)) {
        return ['ok' => false, 'error' => 'Password must contain at least one number.' ];
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $newPassword)) {
        return ['ok' => false, 'error' => 'Password must contain at least one symbol.' ];
    }
    if ($newPassword !== $confirmPassword) {
        return ['ok' => false, 'error' => 'Password confirmation does not match.' ];
    }

    $uStmt = $pdo->prepare(
        'SELECT id, email
         FROM users
         WHERE status = \'active\'
           AND email IS NOT NULL
           AND LOWER(TRIM(email)) = LOWER(TRIM(:email))
         LIMIT 1'
    );
    $uStmt->execute(['email' => $emailNorm]);
    $user = $uStmt->fetch();
    if ($user === false) {
        return ['ok' => false, 'error' => 'Account not found.' ];
    }

    $tokenHash = hash('sha256', $code);
    $nowSql = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $sel = $pdo->prepare(
        'SELECT id
         FROM password_resets
         WHERE user_id = :user_id
           AND token_hash = :token_hash
           AND used_at IS NULL
           AND expires_at >= :now
         ORDER BY requested_at DESC
         LIMIT 1'
    );
    $sel->execute([
        'user_id' => (int) $user['id'],
        'token_hash' => $tokenHash,
        'now' => $nowSql,
    ]);
    $row = $sel->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'Invalid or expired code.' ];
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE users SET password = :pw WHERE id = :id');
        $upd->execute([
            'pw' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $user['id'],
        ]);

        $mark = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
        $mark->execute(['id' => (int) $row['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ok' => true];
}

