<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_session.php';
app_session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/asset_version.php';
require_once __DIR__ . '/includes/ajax_response.php';
require_once __DIR__ . '/includes/auth_password_reset_code.php';

if (!empty($_SESSION['admin_id']) || !empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$pdo = get_pdo();

$error = '';
$flash = '';
$step = 'request'; // request | code | password

// Remember email between steps (but not the code).
$savedEmail = (string) ($_SESSION['pw_reset_email'] ?? '');
if (isset($_GET['email'])) {
    $savedEmail = trim((string) $_GET['email']);
}
$emailValue = $savedEmail;

if (isset($_GET['step'])) {
    $s = (string) $_GET['step'];
    if ($s === 'code') $step = 'code';
    if ($s === 'password') $step = 'password';
}

if (isset($_GET['start_over']) && (string) $_GET['start_over'] === '1') {
    unset($_SESSION['pw_reset_email']);
    unset($_SESSION['pw_reset_verified']);
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_code'])) {
        $email = isset($_POST['email']) ? (string) $_POST['email'] : '';
        $res = auth_password_reset_start_code($pdo, $email);
        if ($res['ok']) {
            $_SESSION['pw_reset_email'] = (string) $res['email'];
            if (ajax_is_requested()) {
                ajax_json_response(true, 'Verification code sent.', '', ['step' => 'code', 'redirect' => 'forgot_password.php?step=code']);
            }
            header('Location: forgot_password.php?step=code');
            exit;
        }
        $error = $res['error'];
        $step = 'request';
        $emailValue = $email;
        if (ajax_is_requested()) {
            ajax_json_response(false, '', $error);
        }
    } elseif (isset($_POST['verify_code'])) {
        $email = isset($_POST['email']) ? (string) $_POST['email'] : $savedEmail;
        $code = isset($_POST['code']) ? (string) $_POST['code'] : '';
        $res = auth_password_reset_verify_code($pdo, $email, $code);
        if ($res['ok']) {
            $_SESSION['pw_reset_email'] = trim((string) $email);
            if (ajax_is_requested()) {
                ajax_json_response(true, 'Code verified.', '', ['step' => 'password', 'redirect' => 'forgot_password.php?step=password']);
            }
            header('Location: forgot_password.php?step=password');
            exit;
        }
        $error = $res['error'];
        $step = 'code';
        $emailValue = $email;
        if (ajax_is_requested()) {
            ajax_json_response(false, '', $error);
        }
    } elseif (isset($_POST['update_password'])) {
        $email = isset($_POST['email']) ? (string) $_POST['email'] : $savedEmail;
        $pw = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $pw2 = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';
        $res = auth_password_reset_verify_and_update($pdo, $email, '', $pw, $pw2);
        if ($res['ok']) {
            unset($_SESSION['pw_reset_email']);
            unset($_SESSION['pw_reset_verified']);
            if (ajax_is_requested()) {
                ajax_json_response(true, 'Password updated.', '', ['redirect' => 'login.php?pw_reset=1']);
            }
            header('Location: login.php?pw_reset=1');
            exit;
        }
        $error = $res['error'];
        $step = 'password';
        $emailValue = $email;
        if (ajax_is_requested()) {
            ajax_json_response(false, '', $error);
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password — EVSU RFID Library</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset_with_version('assets/login.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="login-page">
    <div class="login-wrap">
        <main class="login-card" aria-labelledby="fpTitle">
            <header class="login-brand">
                <span class="login-brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M12 3v2"/><path d="M12 19v2"/><path d="M4 12H2"/><path d="M22 12h-2"/><circle cx="12" cy="12" r="4"/></svg>
                </span>
                <h1 id="fpTitle">Forgot password</h1>
                <?php if ($step === 'request'): ?>
                    <p>Enter your email to receive a verification code.</p>
                <?php elseif ($step === 'code'): ?>
                    <p>Enter the verification code from your email.</p>
                <?php else: ?>
                    <p>Set a new password for your account.</p>
                <?php endif; ?>
            </header>

            <p id="ajaxErr" class="login-alert" role="alert" hidden></p>
            <?php if ($flash !== ''): ?>
                <p class="login-alert login-alert-ok" role="status"><?= h($flash) ?></p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="login-alert" role="alert"><?= h($error) ?></p>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
                <form method="post" action="" id="fpRequestForm" novalidate>
                    <input type="hidden" name="request_code" value="1">
                    <input type="hidden" name="__ajax" value="0" id="fpAjaxFlag">

                    <div class="login-field">
                        <label for="email">Email</label>
                        <div class="login-input-wrap">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>
                            </span>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                required
                                autofocus
                                placeholder="Enter your email"
                                value="<?= h($emailValue) ?>"
                            >
                        </div>
                    </div>

                    <button class="login-submit" type="submit">Send verification code</button>
                </form>
            <?php elseif ($step === 'code'): ?>
                <form method="post" action="" id="fpCodeForm" novalidate>
                    <input type="hidden" name="verify_code" value="1">
                    <input type="hidden" name="__ajax" value="0" id="fpCodeAjaxFlag">

                    <div class="login-field">
                        <label for="email2">Email</label>
                        <div class="login-input-wrap">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>
                            </span>
                            <input
                                id="email2"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                required
                                placeholder="Enter your email"
                                value="<?= h($emailValue) ?>"
                            >
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="code">Verification code</label>
                        <div class="login-input-wrap">
                            <span class="field-icon" aria-hidden="true" style="left:0.95rem;">
                                <svg viewBox="0 0 24 24"><path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><circle cx="12" cy="12" r="3"/></svg>
                            </span>
                            <input
                                id="code"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                autocomplete="one-time-code"
                                required
                                autofocus
                                placeholder="000000"
                                class="login-otp-input"
                                style="padding-left:2.65rem;"
                            >
                        </div>
                    </div>

                    <button class="login-submit" type="submit">Verify code</button>
                </form>

                <form method="post" action="" class="login-otp-resend" id="fpResendForm">
                    <input type="hidden" name="request_code" value="1">
                    <input type="hidden" name="email" value="<?= h($emailValue) ?>">
                    <input type="hidden" name="__ajax" value="0" id="fpResendAjaxFlag">
                    <button type="submit" class="login-link-btn">Resend code</button>
                </form>
            <?php else: ?>
                <form method="post" action="" id="fpPasswordForm" novalidate>
                    <input type="hidden" name="update_password" value="1">
                    <input type="hidden" name="__ajax" value="0" id="fpPwAjaxFlag">

                    <div class="login-field">
                        <label for="email3">Email</label>
                        <div class="login-input-wrap">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/></svg>
                            </span>
                            <input
                                id="email3"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="email"
                                required
                                placeholder="Enter your email"
                                value="<?= h($emailValue) ?>"
                                readonly
                            >
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="password">New password</label>
                        <div class="login-input-wrap has-toggle">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="new-password"
                                minlength="6"
                                required
                                placeholder="Enter new password"
                            >
                            <button type="button" class="login-toggle-pw" id="togglePw1" aria-label="Show password" aria-pressed="false">
                                <svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="password_confirm">Confirm password</label>
                        <div class="login-input-wrap has-toggle">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            <input
                                id="password_confirm"
                                name="password_confirm"
                                type="password"
                                autocomplete="new-password"
                                minlength="6"
                                required
                                placeholder="Confirm new password"
                            >
                            <button type="button" class="login-toggle-pw" id="togglePw2" aria-label="Show password" aria-pressed="false">
                                <svg class="icon-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg class="icon-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>
                            </button>
                        </div>
                    </div>

                    <button class="login-submit" type="submit">Update password</button>
                </form>
            <?php endif; ?>

            <p class="login-foot">
                <a href="login.php">Back to sign in</a>
                <?php if ($step !== 'request'): ?>
                    <span class="muted"> · </span><a href="forgot_password.php?start_over=1">Start over</a>
                <?php endif; ?>
            </p>
        </main>
    </div>

    <script src="assets/app_ajax.js"></script>
    <script>
        (function () {
            function hook(formId, flagId) {
                var f = document.getElementById(formId);
                var err = document.getElementById('ajaxErr');
                var ajaxFlag = document.getElementById(flagId);
                if (!f || !window.ajaxPostForm) return;
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (err) err.hidden = true;
                    if (ajaxFlag) ajaxFlag.value = '1';
                    window.ajaxPostForm(f).then(function (data) {
                        if (data && data.ok && data.redirect) {
                            window.location.href = data.redirect;
                            return;
                        }
                        if (data && data.ok && data.step === 'verify') {
                            window.location.href = data.redirect || 'forgot_password.php?step=verify';
                            return;
                        }
                        if (data && !data.ok && err) {
                            err.textContent = data.message || data.error || 'Request failed.';
                            err.hidden = false;
                        }
                    }).catch(function () {
                        if (err) {
                            err.textContent = 'Network error. Please try again.';
                            err.hidden = false;
                        }
                    });
                });
            }

            hook('fpRequestForm', 'fpAjaxFlag');
            hook('fpCodeForm', 'fpCodeAjaxFlag');
            hook('fpPasswordForm', 'fpPwAjaxFlag');
            hook('fpResendForm', 'fpResendAjaxFlag');

            var code = document.getElementById('code');
            if (code) {
                code.addEventListener('input', function () {
                    code.value = String(code.value || '').replace(/\D/g, '').slice(0, 6);
                });
            }

            function togglePw(inputId, btnId) {
                var pw = document.getElementById(inputId);
                var toggle = document.getElementById(btnId);
                if (!toggle || !pw) return;
                toggle.addEventListener('click', function () {
                    var show = pw.type === 'password';
                    pw.type = show ? 'text' : 'password';
                    toggle.classList.toggle('is-visible', show);
                    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
            }
            togglePw('password', 'togglePw1');
            togglePw('password_confirm', 'togglePw2');
        })();
    </script>
</body>
</html>

