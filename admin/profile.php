<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_bootstrap.php';
require_once __DIR__ . '/../includes/ajax_response.php';
portal_bootstrap();

$admin_id = (int) ($_SESSION['admin_id'] ?? 0);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function ui_avatar_url(string $name): string
{
    $q = http_build_query([
        'name' => $name,
        'rounded' => 'true',
        'background' => '0D1117',
        'color' => 'E6EDF3',
        'bold' => 'true',
        'size' => '128',
        'format' => 'png',
    ]);
    return 'https://ui-avatars.com/api/?' . $q;
}

function public_avatar_src(?string $avatar_path, string $fallback_name): string
{
    if ($avatar_path !== null && $avatar_path !== '') {
        $p = trim((string) $avatar_path);
        if (preg_match('#^https?://#i', $p) === 1 || str_starts_with($p, '//')) {
            return $p;
        }
        return app_public_path($p);
    }
    return ui_avatar_url($fallback_name);
}

function sanitize_upload_ext(string $mime, string $origName): string
{
    $orig = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $mime = strtolower($mime);

    if (in_array($mime, ['image/jpeg', 'image/jpg'], true) || in_array($orig, ['jpg', 'jpeg'], true)) return 'jpg';
    if ($mime === 'image/png' || $orig === 'png') return 'png';
    if ($mime === 'image/webp' || $orig === 'webp') return 'webp';
    return '';
}

$flash = '';
$error = '';

function fetch_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row === false) {
        return 0;
    }
    $v = reset($row);
    return (int) $v;
}

$stmtA = $pdo->prepare('SELECT id, username, avatar_path, library_user_id FROM admins WHERE id = :id');
$stmtA->execute(['id' => $admin_id]);
$admin = $stmtA->fetch();
if ($admin === false) {
    header('Location: logout.php');
    exit;
}

$admin_avatar_src = public_avatar_src((string)($admin['avatar_path'] ?? ''), (string)($admin['username'] ?? 'Admin'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'update_profile') {
            $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
            if ($username === '') {
                throw new RuntimeException('Username is required.');
            }

            $library_user_pick = isset($_POST['library_user_id']) ? trim((string) $_POST['library_user_id']) : '';
            $library_user_val = null;
            if ($library_user_pick !== '' && $library_user_pick !== '0') {
                $picked = (int) $library_user_pick;
                if ($picked <= 0) {
                    throw new RuntimeException('Invalid library profile selection.');
                }
                $chkU = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
                $chkU->execute(['id' => $picked]);
                if ($chkU->fetch() === false) {
                    throw new RuntimeException('Selected library user does not exist.');
                }
                $library_user_val = $picked;
            }

            $newAvatarPath = null;
            if (isset($_FILES['avatar']) && is_array($_FILES['avatar'])) {
                $f = $_FILES['avatar'];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Upload failed.');
                    }
                    $tmp = (string)($f['tmp_name'] ?? '');
                    $name = (string)($f['name'] ?? '');
                    if ($tmp === '' || !is_uploaded_file($tmp)) {
                        throw new RuntimeException('Invalid upload.');
                    }
                    $mime = (string) @mime_content_type($tmp);
                    $ext = sanitize_upload_ext($mime, $name);
                    if ($ext === '') {
                        throw new RuntimeException('Avatar must be JPG, PNG, or WEBP.');
                    }
                    if ((int)($f['size'] ?? 0) > 2 * 1024 * 1024) {
                        throw new RuntimeException('Avatar must be 2MB or smaller.');
                    }

                    $relDir = 'uploads';
                    $absDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relDir;
                    if (!is_dir($absDir)) {
                        @mkdir($absDir, 0775, true);
                    }
                    $filename = 'admin_' . $admin_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($tmp, $absPath)) {
                        throw new RuntimeException('Failed to save uploaded avatar.');
                    }
                    $newAvatarPath = $relDir . '/' . $filename;
                }
            }

            $sql = 'UPDATE admins SET username = :username, library_user_id = :library_user_id';
            $params = [
                'username' => $username,
                'library_user_id' => $library_user_val,
                'id' => $admin_id,
            ];
            if ($newAvatarPath !== null) {
                $sql .= ', avatar_path = :avatar_path';
                $params['avatar_path'] = $newAvatarPath;
            }
            $sql .= ' WHERE id = :id';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $_SESSION['admin_username'] = $username;
            $flash = 'Account updated.';
        } elseif ($action === 'change_password') {
            $current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
            $new1 = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
            $new2 = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

            if ($current === '' || $new1 === '' || $new2 === '') {
                throw new RuntimeException('Fill in all password fields.');
            }
            if ($new1 !== $new2) {
                throw new RuntimeException('New passwords do not match.');
            }
            if (strlen($new1) < 6) {
                throw new RuntimeException('New password must be at least 6 characters.');
            }

            $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE id = :id');
            $stmt->execute(['id' => $admin_id]);
            $row = $stmt->fetch();
            if ($row === false || !password_verify($current, (string)$row['password_hash'])) {
                throw new RuntimeException('Current password is incorrect.');
            }

            $stmt = $pdo->prepare('UPDATE admins SET password_hash = :h WHERE id = :id');
            $stmt->execute(['h' => password_hash($new1, PASSWORD_DEFAULT), 'id' => $admin_id]);
            $flash = 'Password updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $stmtA->execute(['id' => $admin_id]);
    $admin = $stmtA->fetch();

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$stmt_pick = $pdo->query(
    "SELECT id, full_name, role, department, username, status
     FROM users
     ORDER BY (status = 'active') DESC, full_name ASC
     LIMIT 2500"
);
$user_pick_list = $stmt_pick ? $stmt_pick->fetchAll() : [];

$stored_library_uid = 0;
if (isset($admin['library_user_id']) && $admin['library_user_id'] !== null && $admin['library_user_id'] !== '') {
    $stored_library_uid = (int) $admin['library_user_id'];
}

$effective_patron_id = null;
$patron_via_username = false;
if ($stored_library_uid > 0) {
    $effective_patron_id = $stored_library_uid;
} elseif (trim((string)($admin['username'] ?? '')) !== '') {
    $stmtGuess = $pdo->prepare(
        "SELECT id FROM users
         WHERE username IS NOT NULL AND TRIM(username) <> '' AND username = :un
         LIMIT 1"
    );
    $stmtGuess->execute(['un' => (string) $admin['username']]);
    $guess = $stmtGuess->fetch();
    if ($guess !== false) {
        $effective_patron_id = (int) $guess['id'];
        $patron_via_username = true;
    }
}

$patron_profile = null;
$my_borrow_rows = [];
$my_log_rows = [];
$stat_my_borrowed = 0;
$stat_my_overdue = 0;
$stat_my_returned = 0;
$stat_my_lost = 0;
$stat_my_logs_today = 0;
$stat_my_logs_total = 0;
$logs_link_q = '';

$labels_short = [];
$labels_iso = [];
$chart_logs_entries = [];
$chart_logs_exits = [];
$chart_borrow_issued = [];
$chart_borrow_returned = [];
$chart_status_borrowed = 0;
$chart_status_returned = 0;
$chart_status_lost = 0;
$chartDays = 14;
$has_patron_charts = false;

if ($effective_patron_id !== null && $effective_patron_id > 0) {
    $stmtPat = $pdo->prepare(
        'SELECT id, full_name, email, rfid_tag, role, department, status, username, avatar_path
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmtPat->execute(['id' => $effective_patron_id]);
    $patron_profile = $stmtPat->fetch() ?: null;
    if ($patron_profile !== null) {
        $uid = $effective_patron_id;
        $stat_my_borrowed = fetch_count(
            $pdo,
            "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'borrowed'",
            ['u' => $uid]
        );
        $stat_my_overdue = fetch_count(
            $pdo,
            "SELECT COUNT(*) FROM borrowings
             WHERE user_id = :u AND status = 'borrowed'
               AND due_at IS NOT NULL AND due_at < NOW()",
            ['u' => $uid]
        );
        $stat_my_returned = fetch_count(
            $pdo,
            "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'returned'",
            ['u' => $uid]
        );
        $stat_my_lost = fetch_count(
            $pdo,
            "SELECT COUNT(*) FROM borrowings WHERE user_id = :u AND status = 'lost'",
            ['u' => $uid]
        );
        $stat_my_logs_today = fetch_count(
            $pdo,
            "SELECT COUNT(*) FROM entry_exit_logs WHERE user_id = :u AND DATE(scanned_at) = CURDATE()",
            ['u' => $uid]
        );
        $stat_my_logs_total = fetch_count(
            $pdo,
            'SELECT COUNT(*) FROM entry_exit_logs WHERE user_id = :u',
            ['u' => $uid]
        );

        $chart_status_borrowed = $stat_my_borrowed;
        $chart_status_returned = $stat_my_returned;
        $chart_status_lost = $stat_my_lost;

        $stmtBr = $pdo->prepare(
            "SELECT br.id, br.borrowed_at, br.due_at, br.returned_at, br.status, br.note,
                    b.title AS book_title, b.author AS book_author
             FROM borrowings br
             JOIN books b ON b.id = br.book_id
             WHERE br.user_id = :u
             ORDER BY (br.status = 'borrowed') DESC, br.borrowed_at DESC, br.id DESC
             LIMIT 25"
        );
        $stmtBr->execute(['u' => $uid]);
        $my_borrow_rows = $stmtBr->fetchAll();

        $stmtLg = $pdo->prepare(
            "SELECT scanned_at, mode, rfid_tag, note
             FROM entry_exit_logs
             WHERE user_id = :u
             ORDER BY scanned_at DESC, id DESC
             LIMIT 25"
        );
        $stmtLg->execute(['u' => $uid]);
        $my_log_rows = $stmtLg->fetchAll();

        $logs_link_q = trim((string)($patron_profile['rfid_tag'] ?? ''));
        if ($logs_link_q === '') {
            $logs_link_q = trim((string)($patron_profile['full_name'] ?? ''));
        }

        $chartStart = (new DateTimeImmutable('today'))->modify('-' . ($chartDays - 1) . ' days');
        for ($i = 0; $i < $chartDays; $i++) {
            $d = $chartStart->modify('+' . $i . ' days');
            $labels_iso[] = $d->format('Y-m-d');
            $labels_short[] = $d->format('M j');
        }

        $stmtLogsDay = $pdo->prepare(
            "SELECT DATE(scanned_at) AS d,
                    SUM(CASE WHEN mode = 'entry' THEN 1 ELSE 0 END) AS entries,
                    SUM(CASE WHEN mode = 'exit' THEN 1 ELSE 0 END) AS exits
             FROM entry_exit_logs
             WHERE user_id = :u
               AND scanned_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
             GROUP BY DATE(scanned_at)
             ORDER BY d ASC"
        );
        $stmtLogsDay->execute(['u' => $uid]);
        $logsMap = [];
        foreach ($stmtLogsDay->fetchAll() as $row) {
            $logsMap[(string)$row['d']] = [
                'entries' => (int)$row['entries'],
                'exits' => (int)$row['exits'],
            ];
        }
        foreach ($labels_iso as $iso) {
            $day = $logsMap[$iso] ?? [];
            $chart_logs_entries[] = (int)($day['entries'] ?? 0);
            $chart_logs_exits[] = (int)($day['exits'] ?? 0);
        }

        $stmtBorrowDay = $pdo->prepare(
            "SELECT DATE(borrowed_at) AS d, COUNT(*) AS c
             FROM borrowings
             WHERE user_id = :u
               AND borrowed_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
             GROUP BY DATE(borrowed_at)
             ORDER BY d ASC"
        );
        $stmtBorrowDay->execute(['u' => $uid]);
        $borrowMap = [];
        foreach ($stmtBorrowDay->fetchAll() as $row) {
            $borrowMap[(string)$row['d']] = (int)$row['c'];
        }
        foreach ($labels_iso as $iso) {
            $chart_borrow_issued[] = $borrowMap[$iso] ?? 0;
        }

        $stmtReturnDay = $pdo->prepare(
            "SELECT DATE(returned_at) AS d, COUNT(*) AS c
             FROM borrowings
             WHERE user_id = :u
               AND status = 'returned'
               AND returned_at IS NOT NULL
               AND DATE(returned_at) >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
             GROUP BY DATE(returned_at)
             ORDER BY d ASC"
        );
        $stmtReturnDay->execute(['u' => $uid]);
        $returnsMap = [];
        foreach ($stmtReturnDay->fetchAll() as $row) {
            $returnsMap[(string)$row['d']] = (int)$row['c'];
        }
        foreach ($labels_iso as $iso) {
            $chart_borrow_returned[] = $returnsMap[$iso] ?? 0;
        }

        $has_patron_charts = true;
    }
}

$borrowings_filter_href = 'borrowings.php';
if ($patron_profile !== null && trim((string)($patron_profile['full_name'] ?? '')) !== '') {
    $borrowings_filter_href = 'borrowings.php?' . http_build_query(['q' => (string) $patron_profile['full_name']]);
}
$logs_filter_href = 'logs.php';
if ($logs_link_q !== '') {
    $logs_filter_href = 'logs.php?' . http_build_query(['q' => $logs_link_q]);
}

$profile_display_name = $patron_profile !== null
    ? (string) $patron_profile['full_name']
    : (string) ($admin['username'] ?? 'Admin');
$profile_avatar_src = $patron_profile !== null
    ? public_avatar_src((string)($patron_profile['avatar_path'] ?? ''), (string) $patron_profile['full_name'])
    : public_avatar_src((string)($admin['avatar_path'] ?? ''), (string) ($admin['username'] ?? 'Admin'));
$profile_date = (new DateTimeImmutable('now'))->format('l, F j, Y');
$session_name = (string) ($admin['username'] ?? $admin_username ?? 'Admin');
$has_charts = $has_patron_charts;

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My profile — Admin</title>
    <link rel="stylesheet" href="<?= h(portal_asset('assets/admin.css')) ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <style>
        .student-edit-profile-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1.15rem;
            border: none;
            border-radius: 999px;
            font: inherit;
            font-size: 0.9rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, #7c3aed 0%, #6366f1 48%, #38bdf8 100%);
            box-shadow: 0 4px 18px rgba(124, 58, 237, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.12) inset;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        .student-edit-profile-btn svg {
            width: 1.05rem;
            height: 1.05rem;
            flex-shrink: 0;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .student-edit-profile-btn:hover {
            transform: translateY(-1px);
            filter: brightness(1.06);
            box-shadow: 0 8px 26px rgba(124, 58, 237, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.16) inset;
        }
        .student-edit-profile-btn:focus-visible {
            outline: none;
            box-shadow: 0 4px 18px rgba(124, 58, 237, 0.45), 0 0 0 3px rgba(124, 58, 237, 0.35);
        }
        .profile-topbar-aside { align-items: center; gap: 0.75rem; }
        @media (max-width: 640px) {
            .student-edit-profile-btn span.btn-label-long { display: none; }
            .student-edit-profile-btn { padding: 0.65rem 0.85rem; }
        }
    </style>
</head>
<body class="dashboard-page profile-page admin-portal-page">
    <div class="admin-shell">
        <?php require __DIR__ . '/../includes/portal_sidebar.php'; ?>

        <main class="admin-main">
            <div class="container">
                <header class="admin-topbar dashboard-topbar profile-topbar">
                    <div>
                        <p class="dashboard-kicker">Admin / My profile</p>
                        <h1>Welcome, <?= h($session_name) ?></h1>
                        <div class="subtitle">Your borrowing activity, RFID visits, and account settings.</div>
                    </div>
                    <div class="dashboard-topbar-aside profile-topbar-aside">
                        <div class="dashboard-date-pill" title="Server date"><?= h($profile_date) ?></div>
                        <button type="button" class="student-edit-profile-btn" id="openAccountModal" aria-label="Edit profile">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="8" r="4"/><path d="M16 3l5 5"/><path d="M21 3l-5 5"/></svg>
                            <span class="btn-label-long">Edit profile</span>
                        </button>
                    </div>
                </header>

                <p id="ajaxFlash" class="msg" style="display:none;" role="status"></p>
                <?php if ($flash !== ''): ?>
                    <p class="msg ok" role="status"><?= h($flash) ?></p>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <p class="msg err" role="alert"><?= h($error) ?></p>
                <?php endif; ?>

                <section class="card profile-hero saas-card" aria-label="Profile identity">
                    <div class="card-body profile-hero-body">
                        <img class="profile-hero-avatar" alt="" src="<?= h($profile_avatar_src) ?>">
                        <div class="profile-hero-copy">
                            <h2 class="profile-hero-name"><?= h($profile_display_name) ?></h2>
                            <?php if ($patron_profile !== null): ?>
                                <p class="profile-hero-role">
                                    <?= h((string)$patron_profile['role']) ?>
                                    <span class="muted">· <?= h((string)$patron_profile['department']) ?></span>
                                </p>
                                <div class="profile-hero-tags">
                                    <?php if (trim((string)($patron_profile['username'] ?? '')) !== ''): ?>
                                        <span class="profile-tag"><?= h((string)$patron_profile['username']) ?></span>
                                    <?php endif; ?>
                                    <?php if (trim((string)($patron_profile['email'] ?? '')) !== ''): ?>
                                        <span class="profile-tag"><?= h((string)$patron_profile['email']) ?></span>
                                    <?php endif; ?>
                                    <?php if (trim((string)($patron_profile['rfid_tag'] ?? '')) !== ''): ?>
                                        <span class="profile-tag profile-tag--rfid">RFID <?= h((string)$patron_profile['rfid_tag']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string)($patron_profile['status'] ?? '') !== 'active'): ?>
                                        <span class="pill bad">Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($patron_via_username): ?>
                                    <p class="hint profile-hero-hint">Matched by username — open account settings to save this link.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="profile-hero-role">
                                    Admin
                                    <span class="muted">· EVSU RFID Library</span>
                                </p>
                                <div class="profile-hero-tags">
                                    <span class="profile-tag"><?= h((string) ($admin['username'] ?? 'Admin')) ?></span>
                                </div>
                                <p class="hint profile-hero-hint">Use <strong>Edit profile</strong> to link a library user and unlock charts and history.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <?php if ($patron_profile === null && $effective_patron_id !== null): ?>
                    <div class="msg err" role="alert">Linked library profile no longer exists. Open account settings to choose another patron.</div>
                <?php elseif ($patron_profile === null): ?>
                    <section class="card saas-card profile-callout" aria-label="Link library profile">
                        <div class="card-body">
                            <h2 class="card-title">Connect your library profile</h2>
                            <p class="muted" style="margin:0;line-height:1.55;max-width:62ch;">
                                Link your admin account to a row in <strong>Users</strong>, or match by <strong>username</strong>, to see personal KPIs, charts, and borrowing / entry-exit history here.
                            </p>
                        </div>
                    </section>
                <?php else: ?>

                <p class="dashboard-section-label" id="overview">Overview</p>
                <div class="dashboard-stats grid" aria-label="Your statistics">
                    <section class="card dashboard-stat dashboard-stat--borrow<?= $stat_my_overdue > 0 ? ' dashboard-stat--warn' : '' ?>">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-label">Checked out</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$stat_my_borrowed ?></div>
                            <div class="dashboard-stat-foot">
                                <?php if ($stat_my_overdue > 0): ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--warn"><?= (int)$stat_my_overdue ?> overdue</span>
                                <?php else: ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--ok">On track</span>
                                <?php endif; ?>
                                <span class="dashboard-stat-meta">Lost: <?= (int)$stat_my_lost ?></span>
                            </div>
                        </div>
                    </section>
                    <section class="card dashboard-stat dashboard-stat--books">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-label">Returned</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$stat_my_returned ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip">All time</span>
                            </div>
                        </div>
                    </section>
                    <section class="card dashboard-stat dashboard-stat--logs">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-label">RFID scans</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$stat_my_logs_today ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip">Today</span>
                                <span class="dashboard-stat-meta"><?= (int)$stat_my_logs_total ?> total</span>
                            </div>
                        </div>
                    </section>
                </div>

                <?php if ($has_charts): ?>
                <p class="dashboard-section-label" style="margin-top:28px;" id="profile-analytics">Analytics</p>
                <div class="dashboard-charts grid" style="margin-top:10px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Your entry and exit trend">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">My library traffic</h2>
                                    <p class="dashboard-chart-sub">Your entry and exit scans (last <?= (int)$chartDays ?> days).</p>
                                </div>
                                <span class="dashboard-chart-pill"><?= (int)$chartDays ?>-day view</span>
                            </div>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartProfileLogs" role="img" aria-label="Your entries and exits by day"></canvas>
                        </div>
                    </section>
                    <div class="dashboard-charts-right">
                        <section class="card dashboard-chart-card saas-card" aria-label="Your daily issues">
                            <div class="card-body dashboard-chart-head">
                                <h2 class="card-title dashboard-chart-title">My daily issues</h2>
                                <p class="dashboard-chart-sub">Books you borrowed per day</p>
                            </div>
                            <div class="dashboard-chart-canvas-wrap">
                                <canvas id="chartProfileBorrowBar" role="img" aria-label="Your borrowings per day"></canvas>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="dashboard-analytics-row grid half" style="margin-top:22px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Your circulation trend">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">My circulation</h2>
                                    <p class="dashboard-chart-sub">Loans opened vs. returned per day.</p>
                                </div>
                                <span class="dashboard-chart-pill dashboard-chart-pill--muted">Issues vs returns</span>
                            </div>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartProfileCirculation" role="img" aria-label="Your issues and returns by day"></canvas>
                        </div>
                    </section>
                    <section class="card dashboard-chart-card saas-card" aria-label="Your borrowing breakdown">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">Borrowing breakdown</h2>
                                    <p class="dashboard-chart-sub">Current and past loan status</p>
                                </div>
                            </div>
                        </div>
                        <div class="dashboard-chart-canvas-wrap dashboard-chart-canvas-wrap--collection">
                            <?php if ($chart_status_borrowed === 0 && $chart_status_returned === 0 && $chart_status_lost === 0): ?>
                                <p class="dashboard-chart-empty muted" style="text-align:center;padding:2.5rem 1rem;">No borrowing records yet.</p>
                            <?php else: ?>
                            <canvas id="chartProfileStatus" role="img" aria-label="Your borrowing status breakdown"></canvas>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <p class="dashboard-section-label" style="margin-top:28px;">Recent activity</p>
                <div class="grid half" style="margin-top:10px;">
                    <section class="card saas-card" aria-label="Borrowing history">
                        <div class="card-body">
                            <h2 class="card-title">Borrowing history</h2>
                        </div>
                        <div class="table-wrap table-wrap--dashboard-recent">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Borrowed</th>
                                        <th>Status</th>
                                        <th>Book</th>
                                        <th>Due</th>
                                        <th>Returned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$my_borrow_rows): ?>
                                    <tr><td colspan="5" class="muted">Nothing on file yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($my_borrow_rows as $r): ?>
                                        <?php
                                            $status = (string) $r['status'];
                                            $pillClass = $status === 'borrowed' ? 'warn' : 'ok';
                                            if ($status === 'lost') {
                                                $pillClass = 'bad';
                                            }
                                            $due_raw = trim((string) ($r['due_at'] ?? ''));
                                            $is_overdue = $status === 'borrowed'
                                                && $due_raw !== ''
                                                && strtotime($due_raw) !== false
                                                && strtotime($due_raw) < time();
                                        ?>
                                        <tr>
                                            <td><?= h((string) $r['borrowed_at']) ?></td>
                                            <td><span class="pill <?= h($pillClass) ?>"><?= h($status) ?><?php if ($is_overdue): ?> · overdue<?php endif; ?></span></td>
                                            <td>
                                                <?= h((string) $r['book_title']) ?>
                                                <?php if (!empty($r['book_author'])): ?>
                                                    <div class="muted"><?= h((string) $r['book_author']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php if ($is_overdue): ?><strong style="color: var(--warning);"><?php endif; ?><?= h($due_raw) ?><?php if ($is_overdue): ?></strong><?php endif; ?></td>
                                            <td><?= h((string) ($r['returned_at'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="card saas-card" aria-label="Entry exit history">
                        <div class="card-body">
                            <h2 class="card-title">Entry / exit history</h2>
                        </div>
                        <div class="table-wrap table-wrap--dashboard-recent">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Scanned</th>
                                        <th>Mode</th>
                                        <th>RFID</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$my_log_rows): ?>
                                    <tr><td colspan="4" class="muted">No scans matched to you yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($my_log_rows as $l): ?>
                                        <tr>
                                            <td><?= h((string) $l['scanned_at']) ?></td>
                                            <td>
                                                <?php if ((string) $l['mode'] === 'entry'): ?>
                                                    <span class="pill ok">ENTRY</span>
                                                <?php else: ?>
                                                    <span class="pill bad">EXIT</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string) $l['rfid_tag']) ?></td>
                                            <td class="muted"><?= h((string) ($l['note'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <div class="modal" id="accountModal" aria-hidden="true">
        <div class="modal-panel modal-panel-account" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="accountModalTitle">Edit profile</h2>
                <button type="button" class="btn btn-sm" id="closeAccountModal" aria-label="Close">Close</button>
            </div>
            <div class="modal-body modal-body-account">
                <div class="settings-account-row">
                    <img class="avatar" alt="" src="<?= h(public_avatar_src((string)($admin['avatar_path'] ?? ''), (string)$admin['username'])) ?>">
                    <div>
                        <p class="acct-title"><?= h((string)$admin['username']) ?></p>
                        <div class="acct-meta">Admin ID <?= (int)$admin['id'] ?></div>
                    </div>
                </div>

                <div class="profile-modal-tabs" role="tablist" aria-label="Account sections">
                    <button type="button" class="profile-modal-tab is-active" data-tab="profile" role="tab" aria-selected="true">Profile</button>
                    <button type="button" class="profile-modal-tab" data-tab="password" role="tab" aria-selected="false">Password</button>
                </div>

                <div class="profile-modal-pane is-active" id="accountTabProfile" role="tabpanel">
                    <form method="post" action="" enctype="multipart/form-data" id="settingsProfileForm">
                        <input type="hidden" name="action" value="update_profile">

                        <label for="username">Admin username</label>
                        <input id="username" name="username" required value="<?= h((string)$admin['username']) ?>">

                        <label for="library_user_id">Linked borrower (Users)</label>
                        <select id="library_user_id" name="library_user_id">
                            <option value="">Not linked · match by patron username instead</option>
                            <?php foreach ($user_pick_list as $uo): ?>
                            <?php
                                $uo_id = (int) ($uo['id'] ?? 0);
                                $is_sel = ($stored_library_uid > 0 && $stored_library_uid === $uo_id);
                                $inactive = ((string) ($uo['status'] ?? 'active')) !== 'active';
                            ?>
                            <option value="<?= $uo_id ?>"<?= $is_sel ? ' selected' : '' ?>>
                                <?= h((string)($uo['full_name'] ?? '')) ?>
                                <?= $inactive ? ' (inactive)' : '' ?>
                                — <?= h((string)($uo['role'] ?? '')) ?> · <?= h((string)($uo['department'] ?? '')) ?> · ID <?= $uo_id ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">Powers your profile dashboard above.</p>

                        <label for="avatar">Avatar (JPG/PNG/WEBP, max 2MB)</label>
                        <input id="avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp">

                        <button class="btn btn-primary" type="submit">Save profile</button>
                    </form>
                </div>

                <div class="profile-modal-pane" id="accountTabPassword" role="tabpanel" hidden>
                    <form method="post" action="" id="settingsPasswordForm">
                        <input type="hidden" name="action" value="change_password">

                        <label for="current_password">Current</label>
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>

                        <label for="new_password">New</label>
                        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>

                        <label for="confirm_password">Confirm new</label>
                        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>

                        <button class="btn btn-primary" type="submit">Update password</button>
                    </form>
                    <p class="hint">Replace the default after first login.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/app_ajax.js"></script>
    <script>
        (function () {
            function showAjaxFlash(text, isErr) {
                var el = document.getElementById('ajaxFlash');
                if (!el || !text) return;
                el.textContent = text;
                el.className = 'msg ' + (isErr ? 'err' : 'ok');
                el.style.display = '';
                el.setAttribute('role', isErr ? 'alert' : 'status');
            }
            function bind(form) {
                if (!form || !window.ajaxPostForm) return;
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    ajaxPostForm(form).then(function (data) {
                        if (data.ok) window.location.reload();
                        else showAjaxFlash(data.message || data.error || 'Error', true);
                    }).catch(function () { showAjaxFlash('Network error.', true); });
                });
            }
            bind(document.getElementById('settingsProfileForm'));
            bind(document.getElementById('settingsPasswordForm'));

            var accountModal = document.getElementById('accountModal');
            var openBtn = document.getElementById('openAccountModal');
            var closeBtn = document.getElementById('closeAccountModal');

            function openModal(el) {
                el.classList.add('is-open');
                el.setAttribute('aria-hidden', 'false');
            }
            function closeModal(el) {
                el.classList.remove('is-open');
                el.setAttribute('aria-hidden', 'true');
            }
            if (openBtn && accountModal) {
                openBtn.addEventListener('click', function () { openModal(accountModal); });
            }
            if (closeBtn && accountModal) {
                closeBtn.addEventListener('click', function () { closeModal(accountModal); });
            }
            if (accountModal) {
                accountModal.addEventListener('click', function (e) {
                    if (e.target === accountModal) closeModal(accountModal);
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && accountModal && accountModal.classList.contains('is-open')) {
                    closeModal(accountModal);
                }
            });

            var tabs = document.querySelectorAll('.profile-modal-tab');
            var panes = {
                profile: document.getElementById('accountTabProfile'),
                password: document.getElementById('accountTabPassword')
            };
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var key = tab.getAttribute('data-tab');
                    tabs.forEach(function (t) {
                        var on = t === tab;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    Object.keys(panes).forEach(function (k) {
                        var pane = panes[k];
                        if (!pane) return;
                        var show = k === key;
                        pane.classList.toggle('is-active', show);
                        pane.hidden = !show;
                    });
                });
            });

            <?php if ($error !== '' && ($_POST['action'] ?? '') === 'change_password'): ?>
            openModal(accountModal);
            document.querySelector('.profile-modal-tab[data-tab="password"]').click();
            <?php elseif ($error !== ''): ?>
            openModal(accountModal);
            <?php endif; ?>
        })();
    </script>
    <?php if ($has_charts): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script>
    (function () {
        var labels = <?= json_encode($labels_short, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var entries = <?= json_encode($chart_logs_entries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var exits = <?= json_encode($chart_logs_exits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var issued = <?= json_encode($chart_borrow_issued, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var returned = <?= json_encode($chart_borrow_returned, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var statusBorrowed = <?= (int) $chart_status_borrowed ?>;
        var statusReturned = <?= (int) $chart_status_returned ?>;
        var statusLost = <?= (int) $chart_status_lost ?>;

        var grid = 'rgba(255,255,255,.08)';
        var text = 'rgba(255,255,255,.70)';
        Chart.defaults.color = text;
        Chart.defaults.borderColor = grid;
        Chart.defaults.font.family = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

        var commonOpts = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 16 }
                },
                tooltip: {
                    backgroundColor: 'rgba(10, 15, 24, .94)',
                    borderColor: 'rgba(255,255,255,.12)',
                    borderWidth: 1,
                    padding: 12
                }
            }
        };

        var elLogs = document.getElementById('chartProfileLogs');
        if (elLogs) {
            new Chart(elLogs, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Entries', data: entries, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.18)', fill: true, tension: 0.35, pointRadius: 2 },
                        { label: 'Exits', data: exits, borderColor: '#38bdf8', backgroundColor: 'rgba(56,189,248,.14)', fill: true, tension: 0.35, pointRadius: 2 }
                    ]
                },
                options: Object.assign({}, commonOpts, {
                    scales: {
                        x: { grid: { color: grid }, ticks: { maxRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elCirc = document.getElementById('chartProfileCirculation');
        if (elCirc) {
            new Chart(elCirc, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Issued', data: issued, borderColor: '#a78bfa', backgroundColor: 'rgba(167,139,250,.14)', fill: true, tension: 0.35, pointRadius: 2 },
                        { label: 'Returned', data: returned, borderColor: '#f472b6', backgroundColor: 'rgba(244,114,182,.12)', fill: true, tension: 0.35, pointRadius: 2 }
                    ]
                },
                options: Object.assign({}, commonOpts, {
                    scales: {
                        x: { grid: { color: grid }, ticks: { maxRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elBar = document.getElementById('chartProfileBorrowBar');
        if (elBar) {
            new Chart(elBar, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Issued',
                        data: issued,
                        backgroundColor: 'rgba(124,58,237,.45)',
                        borderColor: 'rgba(124,58,237,.85)',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: Object.assign({}, commonOpts, {
                    plugins: Object.assign({}, commonOpts.plugins, { legend: { display: false } }),
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elStatus = document.getElementById('chartProfileStatus');
        if (elStatus) {
            new Chart(elStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Currently borrowed', 'Returned', 'Lost'],
                    datasets: [{
                        data: [statusBorrowed, statusReturned, statusLost],
                        backgroundColor: ['rgba(124,58,237,.72)', 'rgba(45,212,191,.78)', 'rgba(245,158,11,.82)'],
                        borderColor: '#101726',
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: Object.assign({}, commonOpts, {
                    cutout: '58%',
                    plugins: Object.assign({}, commonOpts.plugins, {
                        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } }
                    })
                })
            });
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
