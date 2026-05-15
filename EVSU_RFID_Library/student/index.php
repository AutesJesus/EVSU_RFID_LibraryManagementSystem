<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';
student_require_login();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ajax_response.php';
require_once __DIR__ . '/../includes/patron_activity.php';

$pdo = get_pdo();
$user_id = student_current_user_id();

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

    if (in_array($mime, ['image/jpeg', 'image/jpg'], true) || in_array($orig, ['jpg', 'jpeg'], true)) {
        return 'jpg';
    }
    if ($mime === 'image/png' || $orig === 'png') {
        return 'png';
    }
    if ($mime === 'image/webp' || $orig === 'webp') {
        return 'webp';
    }
    return '';
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'update_profile') {
            $full_name = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
            $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
            $department = isset($_POST['department']) ? trim((string) $_POST['department']) : '';

            if ($full_name === '' || $department === '') {
                throw new RuntimeException('Full name and department are required.');
            }

            $newAvatarPath = null;
            if (isset($_FILES['avatar']) && is_array($_FILES['avatar'])) {
                $f = $_FILES['avatar'];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Upload failed.');
                    }
                    $tmp = (string) ($f['tmp_name'] ?? '');
                    $name = (string) ($f['name'] ?? '');
                    if ($tmp === '' || !is_uploaded_file($tmp)) {
                        throw new RuntimeException('Invalid upload.');
                    }
                    $mime = (string) @mime_content_type($tmp);
                    $ext = sanitize_upload_ext($mime, $name);
                    if ($ext === '') {
                        throw new RuntimeException('Avatar must be JPG, PNG, or WEBP.');
                    }
                    if ((int) ($f['size'] ?? 0) > 2 * 1024 * 1024) {
                        throw new RuntimeException('Avatar must be 2MB or smaller.');
                    }
                    $relDir = 'uploads';
                    $absDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relDir;
                    if (!is_dir($absDir)) {
                        @mkdir($absDir, 0775, true);
                    }
                    $filename = 'user_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
                    if (!move_uploaded_file($tmp, $absPath)) {
                        throw new RuntimeException('Failed to save uploaded avatar.');
                    }
                    $newAvatarPath = $relDir . '/' . $filename;
                }
            }

            $sql = 'UPDATE users SET full_name = :full_name, email = :email, department = :department';
            $params = [
                'full_name' => $full_name,
                'email' => $email !== '' ? $email : null,
                'department' => $department,
                'id' => $user_id,
            ];
            if ($newAvatarPath !== null) {
                $sql .= ', avatar_path = :avatar_path';
                $params['avatar_path'] = $newAvatarPath;
            }
            $sql .= ' WHERE id = :id AND role = \'student\'';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $_SESSION['user_full_name'] = $full_name;
            $flash = 'Profile updated.';
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

            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = :id AND role = \'student\' LIMIT 1');
            $stmt->execute(['id' => $user_id]);
            $row = $stmt->fetch();
            if ($row === false || empty($row['password']) || !password_verify($current, (string) $row['password'])) {
                throw new RuntimeException('Current password is incorrect.');
            }

            $stmt = $pdo->prepare('UPDATE users SET password = :h WHERE id = :id AND role = \'student\'');
            $stmt->execute(['h' => password_hash($new1, PASSWORD_DEFAULT), 'id' => $user_id]);
            $flash = 'Password updated.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$dash = patron_load_dashboard($pdo, $user_id);
$profile = $dash['profile'];
if ($profile === null) {
    header('Location: logout.php');
    exit;
}

$stat_my_borrowed = $dash['stat_my_borrowed'];
$stat_my_overdue = $dash['stat_my_overdue'];
$stat_my_returned = $dash['stat_my_returned'];
$stat_my_lost = $dash['stat_my_lost'];
$stat_my_logs_today = $dash['stat_my_logs_today'];
$stat_my_logs_total = $dash['stat_my_logs_total'];
$my_borrow_rows = $dash['my_borrow_rows'];
$my_log_rows = $dash['my_log_rows'];
$labels_short = $dash['labels_short'];
$chart_logs_entries = $dash['chart_logs_entries'];
$chart_logs_exits = $dash['chart_logs_exits'];
$chart_borrow_issued = $dash['chart_borrow_issued'];
$chart_borrow_returned = $dash['chart_borrow_returned'];
$chart_status_borrowed = $dash['chart_status_borrowed'];
$chart_status_returned = $dash['chart_status_returned'];
$chart_status_lost = $dash['chart_status_lost'];
$chartDays = $dash['chart_days'];
$has_charts = $dash['has_charts'];

$profile_avatar_src = public_avatar_src((string) ($profile['avatar_path'] ?? ''), (string) $profile['full_name']);
$profile_date = (new DateTimeImmutable('now'))->format('l, F j, Y');
$session_name = (string) ($_SESSION['user_full_name'] ?? $profile['full_name']);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My library — Student</title>
    <link rel="stylesheet" href="../admin/assets/admin.css">
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
            box-shadow:
                0 4px 18px rgba(124, 58, 237, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.12) inset;
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
            box-shadow:
                0 8px 26px rgba(124, 58, 237, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.16) inset;
        }
        .student-edit-profile-btn:active {
            transform: translateY(0);
        }
        .student-edit-profile-btn:focus-visible {
            outline: none;
            box-shadow:
                0 4px 18px rgba(124, 58, 237, 0.45),
                0 0 0 3px rgba(124, 58, 237, 0.35);
        }
        .profile-topbar-aside {
            align-items: center;
            gap: 0.75rem;
        }
        @media (max-width: 640px) {
            .student-edit-profile-btn span.btn-label-long { display: none; }
            .student-edit-profile-btn { padding: 0.65rem 0.85rem; }
        }
    </style>
</head>
<body class="dashboard-page profile-page student-portal-page">
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Student navigation">
            <div class="admin-brand">
                <img class="admin-mark" width="38" height="38" alt="" src="<?= h($profile_avatar_src) ?>">
                <div>
                    <div class="admin-brand-title">EVSU Library</div>
                    <div class="admin-brand-sub">Student portal</div>
                </div>
            </div>
            <nav class="admin-nav" aria-label="Student navigation">
                <a class="is-active" href="index.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.8V21h14V9.8"/></svg>
                            </span>
                            <span>Dashboard</span>
                        </span>
                        <small>Overview</small>
                    </span>
                </a>
                <a href="logout.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/><path d="M14 4h6v16h-6"/></svg>
                            </span>
                            <span>Log out</span>
                        </span>
                        <small>Exit</small>
                    </span>
                </a>
            </nav>
        </aside>

        <main class="admin-main">
            <div class="container">
                <header class="admin-topbar dashboard-topbar profile-topbar">
                    <div>
                        <p class="dashboard-kicker">Student / My library</p>
                        <h1>Welcome, <?= h($session_name) ?></h1>
                        <div class="subtitle">Your borrowing activity, RFID visits, and profile settings.</div>
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
                            <h2 class="profile-hero-name"><?= h((string) $profile['full_name']) ?></h2>
                            <p class="profile-hero-role">
                                Student
                                <span class="muted">· <?= h((string) $profile['department']) ?></span>
                            </p>
                            <div class="profile-hero-tags">
                                <?php if (trim((string) ($profile['username'] ?? '')) !== ''): ?>
                                    <span class="profile-tag"><?= h((string) $profile['username']) ?></span>
                                <?php endif; ?>
                                <?php if (trim((string) ($profile['email'] ?? '')) !== ''): ?>
                                    <span class="profile-tag"><?= h((string) $profile['email']) ?></span>
                                <?php endif; ?>
                                <?php if (trim((string) ($profile['rfid_tag'] ?? '')) !== ''): ?>
                                    <span class="profile-tag profile-tag--rfid">RFID <?= h((string) $profile['rfid_tag']) ?></span>
                                <?php endif; ?>
                                <?php if ((string) ($profile['status'] ?? '') !== 'active'): ?>
                                    <span class="pill bad">Inactive</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <p class="dashboard-section-label" id="overview">Overview</p>
                <div class="dashboard-stats grid" aria-label="Your statistics">
                    <section class="card dashboard-stat dashboard-stat--borrow<?= $stat_my_overdue > 0 ? ' dashboard-stat--warn' : '' ?>">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-label">Checked out</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int) $stat_my_borrowed ?></div>
                            <div class="dashboard-stat-foot">
                                <?php if ($stat_my_overdue > 0): ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--warn"><?= (int) $stat_my_overdue ?> overdue</span>
                                <?php else: ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--ok">On track</span>
                                <?php endif; ?>
                                <span class="dashboard-stat-meta">Lost: <?= (int) $stat_my_lost ?></span>
                            </div>
                        </div>
                    </section>
                    <section class="card dashboard-stat dashboard-stat--books">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-label">Returned</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int) $stat_my_returned ?></div>
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
                            <div class="dashboard-stat-value"><?= (int) $stat_my_logs_today ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip">Today</span>
                                <span class="dashboard-stat-meta"><?= (int) $stat_my_logs_total ?> total</span>
                            </div>
                        </div>
                    </section>
                </div>

                <?php if ($has_charts): ?>
                <p class="dashboard-section-label" style="margin-top:28px;" id="analytics">Analytics</p>
                <div class="dashboard-charts grid" style="margin-top:10px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Library traffic">
                        <div class="card-body dashboard-chart-head">
                            <h2 class="card-title dashboard-chart-title">My library traffic</h2>
                            <p class="dashboard-chart-sub">Entry and exit scans (last <?= (int) $chartDays ?> days).</p>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartProfileLogs" role="img" aria-label="Entries and exits by day"></canvas>
                        </div>
                    </section>
                    <div class="dashboard-charts-right">
                        <section class="card dashboard-chart-card saas-card" aria-label="Daily issues">
                            <div class="card-body dashboard-chart-head">
                                <h2 class="card-title dashboard-chart-title">Books borrowed per day</h2>
                            </div>
                            <div class="dashboard-chart-canvas-wrap">
                                <canvas id="chartProfileBorrowBar" role="img" aria-label="Borrowings per day"></canvas>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="dashboard-analytics-row grid half" style="margin-top:22px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Circulation trend">
                        <div class="card-body dashboard-chart-head">
                            <h2 class="card-title dashboard-chart-title">Circulation</h2>
                            <p class="dashboard-chart-sub">Loans opened vs. returned per day.</p>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartProfileCirculation" role="img" aria-label="Issues and returns by day"></canvas>
                        </div>
                    </section>
                    <section class="card dashboard-chart-card saas-card" aria-label="Borrowing breakdown">
                        <div class="card-body dashboard-chart-head">
                            <h2 class="card-title dashboard-chart-title">Borrowing breakdown</h2>
                        </div>
                        <div class="dashboard-chart-canvas-wrap dashboard-chart-canvas-wrap--collection">
                            <?php if ($chart_status_borrowed === 0 && $chart_status_returned === 0 && $chart_status_lost === 0): ?>
                                <p class="dashboard-chart-empty muted" style="text-align:center;padding:2.5rem 1rem;">No borrowing records yet.</p>
                            <?php else: ?>
                            <canvas id="chartProfileStatus" role="img" aria-label="Borrowing status breakdown"></canvas>
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
                <div class="profile-modal-tabs" role="tablist" aria-label="Account sections">
                    <button type="button" class="profile-modal-tab is-active" data-tab="profile" role="tab" aria-selected="true">Profile</button>
                    <button type="button" class="profile-modal-tab" data-tab="password" role="tab" aria-selected="false">Password</button>
                </div>

                <div class="profile-modal-pane is-active" id="accountTabProfile" role="tabpanel">
                    <form method="post" action="" enctype="multipart/form-data" id="settingsProfileForm">
                        <input type="hidden" name="action" value="update_profile">

                        <label for="full_name">Full name</label>
                        <input id="full_name" name="full_name" required value="<?= h((string) $profile['full_name']) ?>">

                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h((string) ($profile['email'] ?? '')) ?>">

                        <label for="department">Department / program</label>
                        <input id="department" name="department" required value="<?= h((string) $profile['department']) ?>">

                        <label>Username</label>
                        <input type="text" value="<?= h((string) ($profile['username'] ?? '')) ?>" disabled>
                        <p class="hint">Ask library staff to change your username.</p>

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
            function openModal(el) {
                el.classList.add('is-open');
                el.setAttribute('aria-hidden', 'false');
            }
            function closeModal(el) {
                el.classList.remove('is-open');
                el.setAttribute('aria-hidden', 'true');
            }
            var openProfileBtn = document.getElementById('openAccountModal');
            if (openProfileBtn && accountModal) {
                openProfileBtn.addEventListener('click', function () { openModal(accountModal); });
            }
            var closeBtn = document.getElementById('closeAccountModal');
            if (closeBtn && accountModal) closeBtn.addEventListener('click', function () { closeModal(accountModal); });
            if (accountModal) {
                accountModal.addEventListener('click', function (e) {
                    if (e.target === accountModal) closeModal(accountModal);
                });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && accountModal && accountModal.classList.contains('is-open')) closeModal(accountModal);
            });

            var tabs = document.querySelectorAll('.profile-modal-tab');
            var panes = { profile: document.getElementById('accountTabProfile'), password: document.getElementById('accountTabPassword') };
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
                        pane.classList.toggle('is-active', k === key);
                        pane.hidden = k !== key;
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

        var commonOpts = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end' } }
        };

        var elLogs = document.getElementById('chartProfileLogs');
        if (elLogs) {
            new Chart(elLogs, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Entries', data: entries, borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.18)', fill: true, tension: 0.35 },
                        { label: 'Exits', data: exits, borderColor: '#38bdf8', backgroundColor: 'rgba(56,189,248,.14)', fill: true, tension: 0.35 }
                    ]
                },
                options: Object.assign({}, commonOpts, { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } })
            });
        }
        var elCirc = document.getElementById('chartProfileCirculation');
        if (elCirc) {
            new Chart(elCirc, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Issued', data: issued, borderColor: '#a78bfa', fill: true, tension: 0.35 },
                        { label: 'Returned', data: returned, borderColor: '#f472b6', fill: true, tension: 0.35 }
                    ]
                },
                options: Object.assign({}, commonOpts, { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } })
            });
        }
        var elBar = document.getElementById('chartProfileBorrowBar');
        if (elBar) {
            new Chart(elBar, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Issued', data: issued, backgroundColor: 'rgba(124,58,237,.45)' }] },
                options: Object.assign({}, commonOpts, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } })
            });
        }
        var elStatus = document.getElementById('chartProfileStatus');
        if (elStatus) {
            new Chart(elStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Borrowed', 'Returned', 'Lost'],
                    datasets: [{ data: [statusBorrowed, statusReturned, statusLost], backgroundColor: ['#7c3aed', '#2dd4bf', '#f59e0b'], borderWidth: 2 }]
                },
                options: { cutout: '58%', plugins: { legend: { position: 'bottom' } } }
            });
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
