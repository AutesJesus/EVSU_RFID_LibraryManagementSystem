<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_session.php';
app_session_start();

// RFID kiosk: optional auto alternation or manual entry/exit; 5s per-tag cooldown (modal only).

require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

$rfid = '';
$flash = '';
$flash_type = 'info';
$show_error_modal = false;
$error_modal_message = '';
$matched_user = null;
$show_cooldown_modal = false;
$cooldown_remaining = 0;

$scan_mode = isset($_SESSION['kiosk_scan_mode']) && $_SESSION['kiosk_scan_mode'] === 'manual' ? 'manual' : 'auto';
$manual_mode = isset($_SESSION['kiosk_manual_mode']) && $_SESSION['kiosk_manual_mode'] === 'exit' ? 'exit' : 'entry';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['scan_mode']) && $_POST['scan_mode'] === 'manual') {
        $scan_mode = 'manual';
    } else {
        $scan_mode = 'auto';
    }
    $_SESSION['kiosk_scan_mode'] = $scan_mode;

    if (isset($_POST['mode'])) {
        $m = (string) $_POST['mode'];
        if ($m === 'exit' || $m === 'entry') {
            $manual_mode = $m;
            $_SESSION['kiosk_manual_mode'] = $manual_mode;
        }
    }

    $rfid = isset($_POST['rfid_tag']) ? trim((string) $_POST['rfid_tag']) : '';
    $rfid = preg_replace('/[\x00\r\n]/', '', $rfid);

    if ($rfid === '') {
        $flash = 'Please scan/enter an RFID tag.';
        $flash_type = 'warn';
    } else {
        $pdo = get_pdo();

        $stmtLast = $pdo->prepare(
            'SELECT mode, TIMESTAMPDIFF(SECOND, scanned_at, NOW()) AS age_sec
             FROM entry_exit_logs
             WHERE rfid_tag = :rfid
             ORDER BY scanned_at DESC, id DESC
             LIMIT 1'
        );
        $stmtLast->execute(['rfid' => $rfid]);
        $lastLog = $stmtLast->fetch() ?: null;

        if ($lastLog !== null) {
            $ageSec = max(0, (int) ($lastLog['age_sec'] ?? 0));
            if ($ageSec < 5) {
                $show_cooldown_modal = true;
                $cooldown_remaining = max(1, 5 - $ageSec);
                $rfid = '';
            }
        }
        if (!$show_cooldown_modal) {
            if ($scan_mode === 'manual') {
                $mode = $manual_mode;
            } else {
                $mode = ($lastLog !== null && ($lastLog['mode'] ?? '') === 'entry') ? 'exit' : 'entry';
            }

            $stmt = $pdo->prepare(
                'SELECT id, full_name, role, department, status
                 FROM users
                 WHERE rfid_tag = :rfid
                 LIMIT 1'
            );
            $stmt->execute(['rfid' => $rfid]);
            $matched_user = $stmt->fetch() ?: null;

            $user_id = null;
            $note = null;

            if ($matched_user) {
                $user_id = (int) $matched_user['id'];
                if ($matched_user['status'] !== 'active') {
                    $note = 'inactive_user';
                    $flash = 'RFID found but user is INACTIVE: ' . $matched_user['full_name'];
                    $flash_type = 'warn';
                } else {
                    $flash = 'Logged ' . strtoupper($mode) . ' for: ' . $matched_user['full_name'] .
                        ' (' . strtoupper((string) $matched_user['role']) . ')';
                    $flash_type = 'ok';
                }
            } else {
                $note = 'unknown_rfid';
                $flash = 'USER NOT FOUND. This RFID is not registered. Please contact the library/admin to register.';
                $flash_type = 'error';
                $show_error_modal = true;
                $error_modal_message = $flash;
            }

            $ins = $pdo->prepare(
                'INSERT INTO entry_exit_logs (user_id, rfid_tag, mode, note)
                 VALUES (:user_id, :rfid_tag, :mode, :note)'
            );
            $ins->execute([
                'user_id' => $user_id,
                'rfid_tag' => $rfid,
                'mode' => $mode,
                'note' => $note,
            ]);

            $rfid = '';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EVSU RFID Library — Entry/Exit</title>
    <style>
        /* Tokens aligned with admin dashboard (admin/assets/admin.css) */
        :root {
            color-scheme: dark;
            --bg0: #0b0f16;
            --panel: #101726;
            --border: rgba(255, 255, 255, 0.1);
            --text: rgba(255, 255, 255, 0.92);
            --muted: rgba(255, 255, 255, 0.62);
            --primary: #7c3aed;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #38bdf8;
            --success: #22c55e;
            --shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
            --ring: 0 0 0 3px rgba(124, 58, 237, 0.35);
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body.scanner-kiosk {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            line-height: 1.45;
            color: var(--text);
            background:
                radial-gradient(920px 440px at 16% -12%, rgba(124, 58, 237, 0.16), transparent 62%),
                radial-gradient(760px 420px at 96% 8%, rgba(56, 189, 248, 0.1), transparent 58%),
                radial-gradient(640px 520px at 50% 108%, rgba(45, 212, 191, 0.06), transparent 55%),
                var(--bg0);
            padding: clamp(14px, 2.5vw, 22px);
            min-height: 100vh;
        }
        a { color: inherit; }
        a:hover { color: rgba(255, 255, 255, 0.95); }

        .shell {
            max-width: 1040px;
            margin: 0 auto;
            min-height: calc(100vh - 28px);
            display: flex;
            flex-direction: column;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .brand h1 {
            margin: 0 0 0.15rem;
            font-size: clamp(1.1rem, 2.6vw, 1.35rem);
            font-weight: 900;
            letter-spacing: 0.2px;
        }
        .brand .sub {
            margin: 0;
            color: var(--muted);
            font-size: 0.86rem;
        }

        .btn {
            appearance: none;
            display: inline-flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            padding: 11px 15px;
            border-radius: 2px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            font-weight: 800;
            font-size: 0.94rem;
            cursor: pointer;
            transition: transform 0.05s ease, background 0.15s ease, border-color 0.15s ease;
        }
        .btn:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.18);
        }
        .btn:active { transform: translateY(1px); }
        .btn:focus-visible { outline: none; box-shadow: var(--ring); }
        .btn-primary {
            background: rgba(124, 58, 237, 0.92);
            border-color: rgba(124, 58, 237, 0.65);
        }
        .btn-primary:hover { background: rgba(124, 58, 237, 0.98); }
        .btn-admin-top {
            flex-shrink: 0;
            padding: 10px 16px;
            font-size: 0.88rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .btn-admin-top svg {
            width: 1.05rem;
            height: 1.05rem;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .scanner-main {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
        }
        .center-scan {
            max-width: 620px;
            margin: 0 auto;
            width: 100%;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow);
            overflow: clip;
            padding: clamp(18px, 3vw, 24px);
        }
        .card-head {
            margin-bottom: 1rem;
        }
        .card-head h2 {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 900;
            letter-spacing: 0.15px;
        }
        .card-head .tagline {
            margin: 0.45rem 0 0;
            font-size: 0.88rem;
            color: var(--muted);
            line-height: 1.5;
            max-width: 42ch;
        }

        .control-block {
            margin-bottom: 1rem;
        }
        .control-block > .hint-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 800;
            color: rgba(255, 255, 255, 0.78);
            margin: 0 0 8px;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .seg {
            display: flex;
            width: 100%;
            max-width: 320px;
            gap: 4px;
            background: rgba(0, 0, 0, 0.28);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 4px;
        }
        .seg input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 0;
            height: 0;
        }
        .seg label {
            flex: 1 1 50%;
            margin: 0;
            cursor: pointer;
            padding: 10px 12px;
            border-radius: 3px;
            font-weight: 800;
            font-size: 0.84rem;
            color: var(--muted);
            border: 1px solid transparent;
            text-align: center;
            line-height: 1.2;
            transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }
        .seg input:focus-visible + label {
            box-shadow: var(--ring);
        }
        .seg input:checked + label {
            background: var(--primary);
            border-color: rgba(124, 58, 237, 0.85);
            color: #fff;
            box-shadow: 0 2px 10px rgba(124, 58, 237, 0.35);
        }
        .manual-row {
            margin-top: 10px;
        }

        .scanner-dock {
            margin: 0 0 1rem;
            border-radius: 8px;
            border: 1px dashed rgba(124, 58, 237, 0.35);
            background: linear-gradient(180deg, rgba(124, 58, 237, 0.08) 0%, rgba(0, 0, 0, 0.22) 100%);
            min-height: 156px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1.25rem 1rem;
        }
        .scanner-dock .waiting-title {
            margin: 0;
            font-size: clamp(1.05rem, 3vw, 1.28rem);
            font-weight: 900;
            letter-spacing: 0.02em;
            line-height: 1.35;
        }
        .scanner-dock .waiting-sub {
            margin: 0.5rem 0 0;
            color: var(--muted);
            font-size: 0.88rem;
            line-height: 1.5;
            max-width: 34ch;
        }

        .scan-form label.field-label {
            display: block;
            text-align: center;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.78);
            margin: 0 0 7px;
        }
        .scan-form input[type="text"] {
            width: 100%;
            min-height: 48px;
            padding: 10px 13px;
            margin-bottom: 12px;
            border-radius: 2px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(0, 0, 0, 0.18);
            color: rgba(255, 255, 255, 0.92);
            font-size: clamp(1.02rem, 2.6vw, 1.18rem);
            font-family: ui-monospace, "Cascadia Code", Menlo, Consolas, monospace;
            text-align: center;
            letter-spacing: 0.06em;
            outline: none;
        }
        .scan-form input[type="text"]::placeholder {
            color: rgba(255, 255, 255, 0.38);
        }
        .scan-form input[type="text"]:focus {
            border-color: rgba(124, 58, 237, 0.55);
            box-shadow: var(--ring);
        }
        .scan-form .btn-primary {
            width: 100%;
        }

        .foot-note {
            margin: 0.75rem 0 0;
            color: var(--muted);
            font-size: 0.86rem;
            text-align: center;
            line-height: 1.5;
        }

        .flash {
            margin: 0 0 1rem;
            padding: 12px 14px;
            border-radius: 2px;
            border: 1px solid var(--border);
            background: rgba(0, 0, 0, 0.2);
        }
        .flash.ok {
            border-color: rgba(34, 197, 94, 0.45);
            background: rgba(34, 197, 94, 0.1);
            color: rgba(187, 247, 208, 0.95);
        }
        .flash.warn {
            border-color: rgba(245, 158, 11, 0.45);
            background: rgba(245, 158, 11, 0.08);
            color: rgba(254, 243, 199, 0.95);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.58);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 1000;
        }
        .modal-backdrop.open { display: flex; }

        .dialog-panel {
            width: min(480px, 96vw);
            border-radius: 0;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: #0a0f18;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .dialog-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.25);
        }
        .dialog-head strong {
            font-size: 1.02rem;
            font-weight: 900;
        }
        .dialog-body { padding: 16px; }
        .dialog-body > p {
            margin: 0 0 12px;
            color: var(--muted);
            line-height: 1.5;
        }
        .dialog-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .dialog-danger {
            border-color: rgba(239, 68, 68, 0.5);
        }
        .dialog-danger .dialog-head {
            border-bottom-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.1);
        }
        .dialog-danger .dialog-body > p {
            color: rgba(254, 226, 226, 0.95);
        }

        .dialog-warn {
            border-color: rgba(245, 158, 11, 0.45);
        }
        .dialog-warn .dialog-head {
            border-bottom-color: rgba(245, 158, 11, 0.35);
            background: rgba(245, 158, 11, 0.1);
        }

        .count-big {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 4px 0 14px;
            min-height: 4.25rem;
            border-radius: 2px;
            background: rgba(0, 0, 0, 0.28);
            border: 1px solid var(--border);
            font-variant-numeric: tabular-nums;
            font-size: clamp(2.2rem, 7vw, 3rem);
            font-weight: 900;
            letter-spacing: 0.06em;
            color: var(--warning);
        }
        .dialog-danger .count-big {
            color: rgba(254, 202, 202, 0.95);
        }

        .xbtn {
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            border-radius: 2px;
            padding: 6px 10px;
            cursor: pointer;
            font-weight: 800;
        }
        .xbtn:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(124, 58, 237, 0.45);
        }
    </style>
</head>
<body class="scanner-kiosk">
    <div class="shell">
        <div class="topbar">
            <div class="brand">
                <h1>EVSU RFID Library</h1>
                <p class="sub">Entry &amp; exit scanner</p>
            </div>
            <?php
            $admin_href = !empty($_SESSION['admin_id']) ? 'admin/index.php' : 'login.php';
            $admin_label = !empty($_SESSION['admin_id']) ? 'Dashboard' : 'Admin';
            ?>
            <a class="btn btn-primary btn-admin-top" href="<?= htmlspecialchars($admin_href, ENT_QUOTES, 'UTF-8') ?>" title="Admin sign-in and dashboard">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span><?= htmlspecialchars($admin_label, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>

        <?php if ($flash !== '' && $flash_type !== 'error'): ?>
            <p class="flash <?= htmlspecialchars($flash_type, ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <div class="scanner-main">
            <section class="card center-scan" aria-label="RFID scan">
                <div class="card-head">
                    <h2>Scanner terminal</h2>
                    <p class="tagline">Use <strong>Auto</strong> to alternate entry and exit per card, or <strong>Manual</strong> to pick each time.</p>
                </div>

                <form method="post" action="" class="scan-form" id="scanForm">
                    <div class="control-block">
                        <span class="hint-label">Scan mode</span>
                        <div class="seg" role="radiogroup" aria-label="Auto or manual entry exit">
                            <input type="radio" name="scan_mode" value="auto" id="scan_auto" <?= $scan_mode === 'auto' ? 'checked' : '' ?>>
                            <label for="scan_auto">Auto</label>
                            <input type="radio" name="scan_mode" value="manual" id="scan_manual" <?= $scan_mode === 'manual' ? 'checked' : '' ?>>
                            <label for="scan_manual">Manual</label>
                        </div>
                        <div class="manual-row" id="manualModeRow" <?= $scan_mode === 'manual' ? '' : 'hidden' ?>>
                            <span class="hint-label" style="margin-top:4px;">Direction</span>
                            <div class="seg" role="radiogroup" aria-label="Entry or exit">
                                <input type="radio" name="mode" value="entry" id="mode_entry" <?= $manual_mode === 'entry' ? 'checked' : '' ?>>
                                <label for="mode_entry">Entry</label>
                                <input type="radio" name="mode" value="exit" id="mode_exit" <?= $manual_mode === 'exit' ? 'checked' : '' ?>>
                                <label for="mode_exit">Exit</label>
                            </div>
                        </div>
                    </div>

                    <div class="scanner-dock" aria-live="polite">
                        <p class="waiting-title">Ready for your RFID card</p>
                        <p class="waiting-sub">Center the reader on this field. Tap your tag when the cursor is here.</p>
                    </div>

                    <label class="field-label" for="rfid_tag">RFID tag</label>
                    <input
                        id="rfid_tag"
                        name="rfid_tag"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Tap card or type UID…"
                        value="<?= htmlspecialchars($rfid, ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($show_error_modal || $show_cooldown_modal) ? '' : 'autofocus' ?>
                    >
                    <button class="btn btn-primary" type="submit">Log scan</button>
                </form>
                <p class="foot-note">Keep this tab focused for wedge scanners. The field clears after each log.</p>
            </section>
        </div>
    </div>

    <div class="modal-backdrop<?= $show_cooldown_modal ? ' open' : '' ?>" id="cooldownModal" aria-hidden="<?= $show_cooldown_modal ? 'false' : 'true' ?>">
        <div class="dialog-panel dialog-warn" role="dialog" aria-modal="true" aria-labelledby="cooldownTitle">
            <header class="dialog-head">
                <strong id="cooldownTitle">Please wait</strong>
                <button class="xbtn" type="button" id="closeCooldown" aria-label="Close">✕</button>
            </header>
            <div class="dialog-body">
                <p>This card was just scanned. Cool down before scanning the same tag again.</p>
                <div class="count-big" id="cooldownCount" aria-live="polite"><?= max(1, (int) $cooldown_remaining) ?></div>
                <p style="margin:0; font-size:0.86rem; color:var(--muted);">Closing automatically when the timer reaches zero.</p>
            </div>
        </div>
    </div>

    <div class="modal-backdrop<?= $show_error_modal ? ' open' : '' ?>" id="scanErrorModal" aria-hidden="<?= $show_error_modal ? 'false' : 'true' ?>">
        <div class="dialog-panel dialog-danger" role="dialog" aria-modal="true" aria-labelledby="errTitle">
            <header class="dialog-head">
                <strong id="errTitle">User not found</strong>
                <button class="xbtn" type="button" id="closeScanError" aria-label="Close">✕</button>
            </header>
            <div class="dialog-body">
                <p><?= htmlspecialchars($error_modal_message !== '' ? $error_modal_message : 'USER NOT FOUND. Please contact the library/admin to register this RFID.', ENT_QUOTES, 'UTF-8') ?></p>
                <div class="count-big" id="errorCountdown" aria-live="polite" style="display:none;">5</div>
                <p style="margin:0 0 12px; font-size:0.86rem; color:var(--muted);">This window closes in a few seconds.</p>
                <div class="dialog-actions">
                    <button class="btn btn-primary" type="button" id="okScanError">Dismiss now</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const COOLDOWN_START = <?= max(0, (int) $cooldown_remaining) ?>;
            const ERROR_CLOSE_START = 5;
            const errorModal = document.getElementById('scanErrorModal');
            const errorCountdown = document.getElementById('errorCountdown');
            const cooldownModal = document.getElementById('cooldownModal');
            const cooldownCount = document.getElementById('cooldownCount');
            const closeCooldown = document.getElementById('closeCooldown');
            const closeScanError = document.getElementById('closeScanError');
            const okScanError = document.getElementById('okScanError');
            const rfidInput = document.getElementById('rfid_tag');
            const manualModeRow = document.getElementById('manualModeRow');
            let errorCountdownTimer = null;
            let cooldownTickTimer = null;

            function focusRfid() {
                if (rfidInput) rfidInput.focus();
            }

            function syncManualRow() {
                if (!manualModeRow) return;
                var manual = document.querySelector('input[name="scan_mode"]:checked');
                manualModeRow.hidden = !(manual && manual.value === 'manual');
            }
            document.querySelectorAll('input[name="scan_mode"]').forEach(function (el) {
                el.addEventListener('change', syncManualRow);
            });
            syncManualRow();

            function stopErrorCountdown() {
                if (errorCountdownTimer) {
                    clearInterval(errorCountdownTimer);
                    errorCountdownTimer = null;
                }
            }

            function closeErrorModal() {
                if (!errorModal) return;
                stopErrorCountdown();
                if (errorCountdown) {
                    errorCountdown.style.display = 'none';
                }
                errorModal.classList.remove('open');
                errorModal.setAttribute('aria-hidden', 'true');
                focusRfid();
            }

            function startErrorCountdown() {
                if (!errorModal || !errorModal.classList.contains('open') || !errorCountdown) return;
                stopErrorCountdown();
                errorCountdown.style.display = 'flex';
                var n = ERROR_CLOSE_START;
                errorCountdown.textContent = String(n);
                errorCountdownTimer = window.setInterval(function () {
                    n -= 1;
                    if (n <= 0) {
                        stopErrorCountdown();
                        closeErrorModal();
                        return;
                    }
                    errorCountdown.textContent = String(n);
                }, 1000);
            }

            if (closeScanError) closeScanError.addEventListener('click', closeErrorModal);
            if (okScanError) okScanError.addEventListener('click', closeErrorModal);
            if (errorModal) {
                errorModal.addEventListener('click', function (e) {
                    if (e.target === errorModal) closeErrorModal();
                });
                if (errorModal.classList.contains('open')) startErrorCountdown();
            }

            function closeCooldownModal() {
                if (!cooldownModal) return;
                if (cooldownTickTimer) {
                    clearInterval(cooldownTickTimer);
                    cooldownTickTimer = null;
                }
                cooldownModal.classList.remove('open');
                cooldownModal.setAttribute('aria-hidden', 'true');
                focusRfid();
            }

            function startCooldownCountdown() {
                if (!cooldownModal || !cooldownCount || COOLDOWN_START <= 0) return;
                var n = COOLDOWN_START;
                cooldownCount.textContent = String(n);
                cooldownTickTimer = window.setInterval(function () {
                    n -= 1;
                    if (n <= 0) {
                        closeCooldownModal();
                        return;
                    }
                    cooldownCount.textContent = String(n);
                }, 1000);
            }
            if (closeCooldown) closeCooldown.addEventListener('click', closeCooldownModal);
            if (cooldownModal) {
                cooldownModal.addEventListener('click', function (e) {
                    if (e.target === cooldownModal) closeCooldownModal();
                });
                if (cooldownModal.classList.contains('open')) startCooldownCountdown();
            }

            document.addEventListener('click', function (e) {
                if (e.target.closest('#scanErrorModal.open')) return;
                if (e.target.closest('#cooldownModal.open')) return;
                if (e.target.closest('a.btn-admin-top')) return;
                if (!rfidInput || e.target === rfidInput || rfidInput.contains(e.target)) return;
                window.setTimeout(focusRfid, 0);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeErrorModal();
                if (e.key === 'Escape' && cooldownModal && cooldownModal.classList.contains('open')) closeCooldownModal();
            });
        })();
    </script>
</body>
</html>
