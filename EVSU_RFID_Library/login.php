<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_session.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/ajax_response.php';
require_once __DIR__ . '/includes/auth_login.php';

auth_redirect_if_logged_in();

if (isset($_GET['cancel'])) {
    auth_clear_admin_rfid_pending();
    header('Location: login.php');
    exit;
}

function login_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$error = '';
$step_rfid = false;
$pending = $_SESSION['admin_login_rfid_pending'] ?? null;

if (is_array($pending)) {
    $exp = (int) ($pending['expires'] ?? 0);
    $aid = (int) ($pending['admin_id'] ?? 0);
    if ($aid <= 0 || $exp < time()) {
        auth_clear_admin_rfid_pending();
        $pending = null;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $error = 'Sign-in step expired. Please sign in again.';
        }
    } else {
        $step_rfid = true;
    }
}

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['admin_rfid_verify'])) {
        $result = auth_verify_admin_rfid(
            $pdo,
            isset($_POST['rfid_capture']) ? (string) $_POST['rfid_capture'] : ''
        );
        if ($result['ok']) {
            header('Location: ' . $result['redirect']);
            exit;
        }
        $error = $result['error'];
        $step_rfid = true;
    } else {
        $login = isset($_POST['login']) ? (string) $_POST['login'] : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $result = auth_login_credentials($pdo, $login, $password);

        if ($result['ok']) {
            if (isset($result['step']) && $result['step'] === 'rfid') {
                if (ajax_is_requested()) {
                    ajax_json_response(true, 'Tap your admin RFID card.', '', ['step' => 'rfid', 'redirect' => 'login.php']);
                }
                header('Location: login.php');
                exit;
            }
            if (ajax_is_requested()) {
                ajax_json_response(true, 'Signed in.', '', ['redirect' => $result['redirect']]);
            }
            header('Location: ' . $result['redirect']);
            exit;
        }

        $error = $result['error'];
        if (ajax_is_requested()) {
            ajax_json_response(false, '', $error);
        }
    }
}

$login_value = isset($_POST['login']) ? trim((string) $_POST['login']) : '';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in — EVSU RFID Library</title>
    <?php if ($step_rfid): ?>
    <link rel="stylesheet" href="admin/assets/admin.css">
    <?php else: ?>
    <link rel="stylesheet" href="assets/login.css">
    <?php endif; ?>
</head>
<body class="login-page<?= $step_rfid ? ' auth-rfid-step' : '' ?>">
    <?php if (!$step_rfid): ?>
    <div class="login-wrap">
        <main class="login-card" aria-labelledby="loginTitle">
            <header class="login-brand">
                <span class="login-brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8"/><path d="M8 11h6"/></svg>
                </span>
                <h1 id="loginTitle">Welcome back</h1>
                <p>Sign in to EVSU RFID Library</p>
            </header>

            <p id="ajaxErr" class="login-alert" role="alert" hidden></p>
            <?php if ($error !== ''): ?>
                <p class="login-alert" role="alert"><?= login_h($error) ?></p>
            <?php endif; ?>

            <form method="post" action="" id="loginForm" novalidate>
                <input type="hidden" name="__ajax" value="0" id="loginAjaxFlag">

                <div class="login-field">
                    <label for="login">Username or email</label>
                    <div class="login-input-wrap">
                        <span class="field-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="8" r="4"/></svg>
                        </span>
                        <input
                            id="login"
                            name="login"
                            type="text"
                            autocomplete="username"
                            required
                            autofocus
                            placeholder="Enter username or email"
                            value="<?= login_h($login_value) ?>"
                        >
                    </div>
                </div>

                <div class="login-field">
                    <label for="password">Password</label>
                    <div class="login-input-wrap has-toggle">
                        <span class="field-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            placeholder="Enter password"
                        >
                        <button type="button" class="login-toggle-pw" id="togglePassword" aria-label="Show password" aria-pressed="false">
                            <svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                        </button>
                    </div>
                </div>

                <button class="login-submit" type="submit">Sign in</button>
            </form>

            <p class="login-foot">EVSU Library Management System</p>
        </main>
    </div>

    <script src="assets/app_ajax.js"></script>
    <script>
        (function () {
            var pw = document.getElementById('password');
            var toggle = document.getElementById('togglePassword');
            if (toggle && pw) {
                toggle.addEventListener('click', function () {
                    var show = pw.type === 'password';
                    pw.type = show ? 'text' : 'password';
                    toggle.classList.toggle('is-visible', show);
                    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
            }

            var f = document.getElementById('loginForm');
            var err = document.getElementById('ajaxErr');
            var ajaxFlag = document.getElementById('loginAjaxFlag');
            if (!f || !window.ajaxPostForm) return;
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                if (err) err.hidden = true;
                if (ajaxFlag) ajaxFlag.value = '1';
                ajaxPostForm(f).then(function (data) {
                    if (data.ok && data.step === 'rfid') {
                        window.location.href = data.redirect || 'login.php';
                        return;
                    }
                    if (data.ok && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    if (data.ok) {
                        window.location.reload();
                        return;
                    }
                    if (err) {
                        err.textContent = data.message || data.error || 'Sign-in failed.';
                        err.hidden = false;
                    }
                }).catch(function () {
                    if (err) {
                        err.textContent = 'Network error. Please try again.';
                        err.hidden = false;
                    }
                });
            });
        })();
    </script>
    <?php endif; ?>

    <?php if ($step_rfid): ?>
    <form method="post" action="" id="adminRfidForm" class="auth-rfid-page-form">
        <input type="hidden" name="admin_rfid_verify" value="1">
        <label for="rfid_capture" class="sr-only">RFID</label>
        <input
            id="rfid_capture"
            name="rfid_capture"
            type="text"
            autocomplete="off"
            spellcheck="false"
            tabindex="0"
            class="auth-rfid-capture"
            aria-hidden="true"
        >
        <div class="auth-rfid-overlay" id="adminRfidBackdrop" aria-hidden="false">
            <div class="auth-rfid-popup" role="dialog" aria-modal="true" aria-labelledby="adminRfidTitle">
                <?php if ($error !== ''): ?>
                    <p class="msg err auth-rfid-popup-err" role="alert"><?= login_h($error) ?></p>
                <?php endif; ?>
                <p id="adminRfidTitle" class="auth-rfid-popup-title">Tap admin RFID</p>
                <p class="auth-rfid-popup-sub">Verification required</p>
                <p class="hint" style="margin:1rem 0 0;">
                    <a href="login.php?cancel=1" style="color:var(--info);">Cancel</a>
                </p>
            </div>
        </div>
    </form>
    <script>
        (function () {
            var input = document.getElementById('rfid_capture');
            var backdrop = document.getElementById('adminRfidBackdrop');
            var popup = backdrop ? backdrop.querySelector('.auth-rfid-popup') : null;
            if (!input || !backdrop) return;
            function focusCapture() {
                try {
                    input.focus();
                    input.select();
                } catch (e) {}
            }
            focusCapture();
            window.setTimeout(focusCapture, 50);
            window.setTimeout(focusCapture, 400);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) focusCapture();
            });
            if (popup) {
                popup.addEventListener('click', function () {
                    window.setTimeout(focusCapture, 0);
                });
            }
            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) {
                    window.location.href = 'login.php?cancel=1';
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    window.location.href = 'login.php?cancel=1';
                }
            });
            var form = document.getElementById('adminRfidForm');
            if (form) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && String(input.value || '').trim() !== '') {
                        form.submit();
                    }
                });
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
