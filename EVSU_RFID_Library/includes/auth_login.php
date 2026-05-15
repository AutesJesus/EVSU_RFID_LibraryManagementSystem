<?php
declare(strict_types=1);

function auth_normalize_rfid(string $raw): string
{
    $s = preg_replace('/[\x00\r\n]/', '', trim($raw));

    return rtrim($s, '|');
}

function auth_clear_admin_rfid_pending(): void
{
    unset($_SESSION['admin_login_rfid_pending']);
}

/**
 * Send already-authenticated users to their dashboard.
 */
function auth_redirect_if_logged_in(): void
{
    if (!empty($_SESSION['admin_id'])) {
        header('Location: admin/index.php');
        exit;
    }
    if (!empty($_SESSION['user_id'])) {
        $role = (string) ($_SESSION['user_role'] ?? '');
        if ($role === 'student') {
            header('Location: student/index.php');
            exit;
        }
        if (in_array($role, ['faculty', 'librarian'], true)) {
            header('Location: faculty/index.php');
            exit;
        }
    }
}

/**
 * @return array{ok: true, redirect: string}|array{ok: false, error: string}
 */
function auth_login_patron(PDO $pdo, string $login, string $password): array
{
    $login = trim($login);
    if ($login === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your username or email and password.'];
    }

    $stmt = $pdo->prepare(
        "SELECT id, full_name, username, password, role, status, email
         FROM users
         WHERE status = 'active'
           AND (
             (username IS NOT NULL AND TRIM(username) <> '' AND username = :login)
             OR (email IS NOT NULL AND TRIM(email) <> '' AND LOWER(TRIM(email)) = LOWER(:login))
           )
         LIMIT 1"
    );
    $stmt->execute(['login' => $login]);
    $u = $stmt->fetch();

    if ($u === false || empty($u['password']) || !password_verify($password, (string) $u['password'])) {
        return ['ok' => false, 'error' => 'Invalid username, email, or password.'];
    }

    $role = (string) ($u['role'] ?? '');
    if (!in_array($role, ['student', 'faculty', 'librarian'], true)) {
        return ['ok' => false, 'error' => 'This account cannot sign in here.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_full_name'] = (string) $u['full_name'];
    $_SESSION['user_role'] = $role;
    auth_clear_admin_rfid_pending();

    $redirect = $role === 'student' ? 'student/index.php' : 'faculty/index.php';

    return ['ok' => true, 'redirect' => $redirect];
}

/**
 * @return array{ok: true, step: 'rfid'}|array{ok: false, error: string}
 */
function auth_login_admin_password(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your admin username and password.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    session_regenerate_id(true);
    $_SESSION['admin_login_rfid_pending'] = [
        'admin_id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'expires' => time() + 300,
    ];

    return ['ok' => true, 'step' => 'rfid'];
}

/**
 * @return array{ok: true, redirect: string}|array{ok: false, error: string}
 */
function auth_verify_admin_rfid(PDO $pdo, string $rfidRaw): array
{
    $rfid = auth_normalize_rfid($rfidRaw);
    $pending = $_SESSION['admin_login_rfid_pending'] ?? null;

    if (!is_array($pending) || (int) ($pending['admin_id'] ?? 0) <= 0 || (int) ($pending['expires'] ?? 0) < time()) {
        auth_clear_admin_rfid_pending();

        return ['ok' => false, 'error' => 'Please sign in with username and password first.'];
    }

    if ($rfid === '') {
        return ['ok' => false, 'error' => 'No RFID received. Tap your admin card on the reader.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, rfid_tag FROM admins WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $pending['admin_id']]);
    $row = $stmt->fetch();

    if ($row === false) {
        auth_clear_admin_rfid_pending();

        return ['ok' => false, 'error' => 'Invalid session. Please sign in again.'];
    }

    $expected = auth_normalize_rfid((string) ($row['rfid_tag'] ?? ''));
    if ($expected === '') {
        return ['ok' => false, 'error' => 'This admin account has no RFID enrolled. Contact IT or update the database.'];
    }
    if (!hash_equals($expected, $rfid)) {
        return ['ok' => false, 'error' => 'RFID does not match this admin account. Tap the correct admin card.'];
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $row['id'];
    $_SESSION['admin_username'] = (string) $row['username'];
    auth_clear_admin_rfid_pending();
    unset($_SESSION['user_id'], $_SESSION['user_full_name'], $_SESSION['user_role']);

    return ['ok' => true, 'redirect' => 'admin/index.php'];
}

/**
 * Credential step: patrons (username/email) first when login looks like email;
 * otherwise try admin username, then patron username.
 *
 * @return array{ok: true, redirect: string}|array{ok: true, step: 'rfid'}|array{ok: false, error: string}
 */
function auth_login_credentials(PDO $pdo, string $login, string $password): array
{
    $login = trim($login);
    $looksLikeEmail = str_contains($login, '@');

    if ($looksLikeEmail) {
        return auth_login_patron($pdo, $login, $password);
    }

    $adminTry = auth_login_admin_password($pdo, $login, $password);
    if ($adminTry['ok']) {
        return $adminTry;
    }

    $patronTry = auth_login_patron($pdo, $login, $password);
    if ($patronTry['ok']) {
        return $patronTry;
    }

    return ['ok' => false, 'error' => 'Invalid username, email, or password.'];
}
