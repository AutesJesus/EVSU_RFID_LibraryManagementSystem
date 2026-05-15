<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_bootstrap.php';
portal_bootstrap();

header('Content-Type: text/html; charset=utf-8');

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
        'size' => '96',
        'format' => 'png',
    ]);
    return 'https://ui-avatars.com/api/?' . $q;
}

function public_avatar_src(?string $avatar_path, string $fallback_name): string
{
    if ($avatar_path !== null && $avatar_path !== '') {
        return '../' . ltrim($avatar_path, '/');
    }
    return ui_avatar_url($fallback_name);
}

function fetch_count(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row === false) return 0;
    $v = reset($row);
    return (int) $v;
}

// Stats
$users_total = fetch_count($pdo, 'SELECT COUNT(*) FROM users');
$users_active = fetch_count($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'");

$logs_total = fetch_count($pdo, 'SELECT COUNT(*) FROM entry_exit_logs');
$logs_today = fetch_count($pdo, 'SELECT COUNT(*) FROM entry_exit_logs WHERE DATE(scanned_at) = CURDATE()');
$entries_today = fetch_count($pdo, "SELECT COUNT(*) FROM entry_exit_logs WHERE DATE(scanned_at) = CURDATE() AND mode = 'entry'");
$exits_today = fetch_count($pdo, "SELECT COUNT(*) FROM entry_exit_logs WHERE DATE(scanned_at) = CURDATE() AND mode = 'exit'");

$books_total = fetch_count($pdo, 'SELECT COUNT(*) FROM books');
$books_active = fetch_count($pdo, "SELECT COUNT(*) FROM books WHERE status = 'active'");
$books_archived = fetch_count($pdo, "SELECT COUNT(*) FROM books WHERE status = 'archived'");
$copies_total = fetch_count($pdo, 'SELECT COALESCE(SUM(copies_total), 0) FROM books');
$copies_available = fetch_count($pdo, "SELECT COALESCE(SUM(copies_available), 0) FROM books WHERE status = 'active'");

$borrow_total = fetch_count($pdo, 'SELECT COUNT(*) FROM borrowings');
$borrow_open = fetch_count($pdo, "SELECT COUNT(*) FROM borrowings WHERE status = 'borrowed'");
$borrow_issued_today = fetch_count($pdo, "SELECT COUNT(*) FROM borrowings WHERE DATE(borrowed_at) = CURDATE()");
$borrow_returned_today = fetch_count($pdo, "SELECT COUNT(*) FROM borrowings WHERE status = 'returned' AND DATE(returned_at) = CURDATE()");
$borrow_overdue = fetch_count(
    $pdo,
    "SELECT COUNT(*)
     FROM borrowings
     WHERE status = 'borrowed'
       AND due_at IS NOT NULL
       AND due_at < NOW()"
);
$borrow_lost = fetch_count($pdo, "SELECT COUNT(*) FROM borrowings WHERE status = 'lost'");

$recentLogsStmt = $pdo->query(
    "SELECT l.scanned_at, l.mode, l.rfid_tag, u.full_name, u.role, u.department
     FROM entry_exit_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.scanned_at DESC, l.id DESC
     LIMIT 20"
);
$recent_logs = $recentLogsStmt->fetchAll();

$recentBorrowStmt = $pdo->query(
    "SELECT br.borrowed_at, br.due_at, br.returned_at, br.status,
            u.full_name, u.role, b.title AS book_title
     FROM borrowings br
     JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     ORDER BY br.id DESC
     LIMIT 20"
);
$recent_borrowings = $recentBorrowStmt->fetchAll();

$chartDays = 14;
$chartStart = new DateTimeImmutable('today');
$chartStart = $chartStart->modify('-' . ($chartDays - 1) . ' days');

$labels_iso = [];
$labels_short = [];
for ($i = 0; $i < $chartDays; $i++) {
    $d = $chartStart->modify('+' . $i . ' days');
    $labels_iso[] = $d->format('Y-m-d');
    $labels_short[] = $d->format('M j');
}

$logsByDayStmt = $pdo->query(
    "SELECT DATE(scanned_at) AS d,
            SUM(CASE WHEN mode = 'entry' THEN 1 ELSE 0 END) AS entries,
            SUM(CASE WHEN mode = 'exit' THEN 1 ELSE 0 END) AS exits
     FROM entry_exit_logs
     WHERE scanned_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
     GROUP BY DATE(scanned_at)
     ORDER BY d ASC"
);
$logsMap = [];
foreach ($logsByDayStmt->fetchAll() as $row) {
    $logsMap[(string)$row['d']] = [
        'entries' => (int)$row['entries'],
        'exits' => (int)$row['exits'],
    ];
}

$chart_logs_entries = [];
$chart_logs_exits = [];
foreach ($labels_iso as $iso) {
    $day = $logsMap[$iso] ?? [];
    $chart_logs_entries[] = (int)($day['entries'] ?? 0);
    $chart_logs_exits[] = (int)($day['exits'] ?? 0);
}

$borrowByDayStmt = $pdo->query(
    "SELECT DATE(borrowed_at) AS d, COUNT(*) AS c
     FROM borrowings
     WHERE borrowed_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
     GROUP BY DATE(borrowed_at)
     ORDER BY d ASC"
);
$borrowMap = [];
foreach ($borrowByDayStmt->fetchAll() as $row) {
    $borrowMap[(string)$row['d']] = (int)$row['c'];
}
$chart_borrow_issued = [];
foreach ($labels_iso as $iso) {
    $chart_borrow_issued[] = $borrowMap[$iso] ?? 0;
}

$returnsByDayStmt = $pdo->query(
    "SELECT DATE(returned_at) AS d, COUNT(*) AS c
     FROM borrowings
     WHERE status = 'returned'
       AND returned_at IS NOT NULL
       AND DATE(returned_at) >= DATE_SUB(CURDATE(), INTERVAL " . (int)($chartDays - 1) . " DAY)
     GROUP BY DATE(returned_at)
     ORDER BY d ASC"
);
$returnsMap = [];
foreach ($returnsByDayStmt->fetchAll() as $row) {
    $returnsMap[(string)$row['d']] = (int)$row['c'];
}
$chart_borrow_returned = [];
foreach ($labels_iso as $iso) {
    $chart_borrow_returned[] = $returnsMap[$iso] ?? 0;
}

$collectionStmt = $pdo->query(
    "SELECT
        COALESCE(SUM(copies_available), 0) AS avail,
        COALESCE(SUM(GREATEST(CAST(copies_total AS SIGNED) - CAST(copies_available AS SIGNED), 0)), 0) AS out_copies
     FROM books
     WHERE status = 'active'"
);
$collectionRow = $collectionStmt->fetch() ?: [];
$collection_available = (int)($collectionRow['avail'] ?? 0);
$collection_on_loan = (int)($collectionRow['out_copies'] ?? 0);
$collection_active_copies = $collection_available + $collection_on_loan;

$userRolesStmt = $pdo->query(
    "SELECT COALESCE(NULLIF(TRIM(role), ''), '—') AS role_label, COUNT(*) AS c
     FROM users
     GROUP BY COALESCE(NULLIF(TRIM(role), ''), '—')
     ORDER BY c DESC"
);
$user_role_labels = [];
$user_role_counts = [];
foreach ($userRolesStmt->fetchAll() as $row) {
    $user_role_labels[] = (string)$row['role_label'];
    $user_role_counts[] = (int)$row['c'];
}

$dashboard_date = (new DateTimeImmutable('now'))->format('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — EVSU RFID Library</title>
    <link rel="stylesheet" href="<?= h(portal_asset('assets/admin.css')) ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
</head>
<body class="dashboard-page">
    <div class="admin-shell">
        <?php require __DIR__ . '/../includes/portal_sidebar.php'; ?>

        <main class="admin-main">
            <div class="container">
                <header class="admin-topbar dashboard-topbar">
                    <div>
                        <p class="dashboard-kicker">Welcome back, <?= h($admin_username) ?></p>
                        <h1>Dashboard</h1>
                        <div class="subtitle">Live picture of library traffic, inventory health, and circulation.</div>
                    </div>
                    <div class="dashboard-topbar-aside">
                        <div class="dashboard-date-pill" title="Server date"><?= h($dashboard_date) ?></div>
                    </div>
                </header>

                <p class="dashboard-section-label" id="dash-overview">Overview</p>
                <div class="dashboard-stats grid" aria-label="Statistics">
                    <section class="card dashboard-stat dashboard-stat--users">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-ico" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M16 11a4 4 0 1 0-8 0"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                                </span>
                                <span class="dashboard-stat-label">Patrons</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$users_total ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip dashboard-stat-chip--ok"><?= (int)$users_active ?> active</span>
                                <span class="dashboard-stat-meta">Registered users</span>
                            </div>
                        </div>
                    </section>

                    <section class="card dashboard-stat dashboard-stat--logs">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-ico" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                                </span>
                                <span class="dashboard-stat-label">RFID scans today</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$logs_today ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip"><?= (int)$entries_today ?> in</span>
                                <span class="dashboard-stat-chip"><?= (int)$exits_today ?> out</span>
                                <span class="dashboard-stat-meta"><?= (int)$logs_total ?> all-time</span>
                            </div>
                        </div>
                    </section>

                    <section class="card dashboard-stat dashboard-stat--books">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-ico" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M7 3h10v18H7z"/><path d="M7 7h10"/></svg>
                                </span>
                                <span class="dashboard-stat-label">Collection</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$books_active ?></div>
                            <div class="dashboard-stat-foot">
                                <span class="dashboard-stat-chip"><?= (int)$copies_available ?> copies free</span>
                                <span class="dashboard-stat-meta"><?= (int)$books_total ?> titles · <?= (int)$copies_total ?> total copies</span>
                            </div>
                        </div>
                    </section>

                    <section class="card dashboard-stat dashboard-stat--borrow<?= $borrow_overdue > 0 ? ' dashboard-stat--warn' : '' ?>">
                        <div class="card-body">
                            <div class="dashboard-stat-head">
                                <span class="dashboard-stat-ico" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M7 4h10v16H7z"/><path d="M17 8h2a2 2 0 0 1 2 2v10H7"/></svg>
                                </span>
                                <span class="dashboard-stat-label">Circulation</span>
                            </div>
                            <div class="dashboard-stat-value"><?= (int)$borrow_open ?></div>
                            <div class="dashboard-stat-foot">
                                <?php if ($borrow_overdue > 0): ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--warn"><?= (int)$borrow_overdue ?> overdue</span>
                                <?php else: ?>
                                    <span class="dashboard-stat-chip dashboard-stat-chip--ok">No overdue</span>
                                <?php endif; ?>
                                <span class="dashboard-stat-meta">+<?= (int)$borrow_issued_today ?> issued · <?= (int)$borrow_returned_today ?> returned today</span>
                            </div>
                        </div>
                    </section>
                </div>

                <p class="dashboard-section-label" id="dash-analytics" style="margin-top: 28px;">Analytics</p>
                <div class="dashboard-charts grid" style="margin-top: 10px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Entry and exit trend">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">Library traffic</h2>
                                    <p class="dashboard-chart-sub">Daily entry and exit scans (last <?= (int)$chartDays ?> days).</p>
                                </div>
                                <span class="dashboard-chart-pill" title="Rolling window"><?= (int)$chartDays ?>-day view</span>
                            </div>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartLogs" role="img" aria-label="Line chart of entries and exits by day"></canvas>
                        </div>
                    </section>
                    <div class="dashboard-charts-right">
                        <section class="card dashboard-chart-card saas-card" aria-label="Borrowing issued per day">
                            <div class="card-body dashboard-chart-head">
                                <h2 class="card-title dashboard-chart-title">Daily issues</h2>
                                <p class="dashboard-chart-sub">New loans opened each day</p>
                            </div>
                            <div class="dashboard-chart-canvas-wrap">
                                <canvas id="chartBorrowBar" role="img" aria-label="Bar chart of borrowings per day"></canvas>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="dashboard-analytics-row grid half" style="margin-top: 22px;">
                    <section class="card dashboard-chart-card saas-card" aria-label="Issued versus returned trend">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">Circulation trend</h2>
                                    <p class="dashboard-chart-sub">Loans opened vs. closed per day — spot busy periods and return patterns.</p>
                                </div>
                                <span class="dashboard-chart-pill dashboard-chart-pill--muted">Issues vs returns</span>
                            </div>
                        </div>
                        <div class="dashboard-chart-canvas-wrap">
                            <canvas id="chartCirculation" role="img" aria-label="Line chart of issues and returns by day"></canvas>
                        </div>
                    </section>
                    <section class="card dashboard-chart-card saas-card" aria-label="Active collection availability">
                        <div class="card-body dashboard-chart-head">
                            <div class="dashboard-chart-head-main">
                                <div>
                                    <h2 class="card-title dashboard-chart-title">Shelf vs on loan</h2>
                                </div>
                            </div>
                        </div>
                        <div class="dashboard-inventory-summary card-body" aria-label="Inventory summary">
                            <dl class="dashboard-inventory-summary__grid dashboard-inventory-summary__grid--five">
                                <div class="dashboard-inventory-summary__item">
                                    <dt>Active copy stock</dt>
                                    <dd><?= number_format($collection_active_copies) ?></dd>
                                </div>
                                <div class="dashboard-inventory-summary__item">
                                    <dt>On shelves</dt>
                                    <dd><?= number_format($collection_available) ?></dd>
                                </div>
                                <div class="dashboard-inventory-summary__item">
                                    <dt>Checked out</dt>
                                    <dd><?= number_format($collection_on_loan) ?></dd>
                                </div>
                                <div class="dashboard-inventory-summary__item">
                                    <dt>Open loans</dt>
                                    <dd><?= number_format($borrow_open) ?></dd>
                                </div>
                                <div class="dashboard-inventory-summary__item">
                                    <dt>Lost books</dt>
                                    <dd><?= number_format($borrow_lost) ?></dd>
                                </div>
                                <?php if ($books_archived > 0): ?>
                                <div class="dashboard-inventory-summary__item dashboard-inventory-summary__item--wide">
                                    <dt>Archived titles</dt>
                                    <dd><?= number_format($books_archived) ?> <span class="muted">(excluded from chart)</span></dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </div>
                        <div class="dashboard-chart-canvas-wrap dashboard-chart-canvas-wrap--collection">
                            <?php if ($collection_available === 0 && $collection_on_loan === 0 && $borrow_lost === 0): ?>
                                <p class="dashboard-chart-empty muted" style="text-align:center;padding:2.5rem 1rem;">No copy or lost-loan data yet. Add books and circulation to see the chart.</p>
                            <?php else: ?>
                            <canvas id="chartCollection" role="img" aria-label="Doughnut chart of shelf stock, open loans, and lost books"></canvas>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <?php if ($user_role_counts !== [] && array_sum($user_role_counts) > 0): ?>
                <section class="card dashboard-chart-card dashboard-chart-card--roles saas-card" style="margin-top: 22px;" aria-label="Users by role">
                    <div class="card-body dashboard-chart-head">
                        <div class="dashboard-chart-head-main">
                            <div>
                                <h2 class="card-title dashboard-chart-title">Patrons by role</h2>
                                <p class="dashboard-chart-sub">How your user base breaks down</p>
                            </div>
                            <span class="dashboard-chart-pill dashboard-chart-pill--muted"><?= (int) array_sum($user_role_counts) ?> users</span>
                        </div>
                    </div>
                    <div class="dashboard-chart-canvas-wrap">
                        <canvas id="chartRoles" role="img" aria-label="Horizontal bar chart of users by role"></canvas>
                    </div>
                </section>
                <?php endif; ?>

                <p class="dashboard-section-label" style="margin-top: 28px;">Recent activity</p>
                <div class="grid half" style="margin-top:10px;">
                    <section class="card saas-card" aria-label="Recent entry/exit logs">
                        <div class="card-body">
                            <h2 class="card-title">Recent entry/exit</h2>
                        </div>
                        <div class="table-wrap table-wrap--dashboard-recent">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Scanned</th>
                                        <th>Mode</th>
                                        <th>RFID</th>
                                        <th>User</th>
                                        <th>Dept</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$recent_logs): ?>
                                    <tr><td colspan="5" class="muted">No logs yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_logs as $l): ?>
                                        <tr>
                                            <td><?= h((string)$l['scanned_at']) ?></td>
                                            <td>
                                                <?php if ((string)$l['mode'] === 'entry'): ?>
                                                    <span class="pill ok">ENTRY</span>
                                                <?php else: ?>
                                                    <span class="pill bad">EXIT</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string)$l['rfid_tag']) ?></td>
                                            <td><?= h((string)($l['full_name'] ?? '—')) ?> <?php if (!empty($l['role'])): ?><span class="muted">(<?= h((string)$l['role']) ?>)</span><?php endif; ?></td>
                                            <td><?= h((string)($l['department'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-body">
                            <a class="btn btn-sm" href="logs.php">Open logs</a>
                        </div>
                    </section>

                    <section class="card saas-card" aria-label="Recent borrowings">
                        <div class="card-body">
                            <h2 class="card-title">Recent borrowings</h2>
                        </div>
                        <div class="table-wrap table-wrap--dashboard-recent">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Borrowed</th>
                                        <th>Status</th>
                                        <th>User</th>
                                        <th>Book</th>
                                        <th>Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$recent_borrowings): ?>
                                    <tr><td colspan="5" class="muted">No borrowings yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_borrowings as $r): ?>
                                        <?php
                                            $st = (string)$r['status'];
                                            $cls = ($st === 'returned') ? 'ok' : (($st === 'lost') ? 'warn' : 'bad');
                                        ?>
                                        <tr>
                                            <td><?= h((string)$r['borrowed_at']) ?></td>
                                            <td><span class="pill <?= h($cls) ?>"><?= h($st) ?></span></td>
                                            <td><?= h((string)$r['full_name']) ?> <span class="muted">(<?= h((string)$r['role']) ?>)</span></td>
                                            <td><?= h((string)$r['book_title']) ?></td>
                                            <td><?= h((string)($r['due_at'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-body">
                            <a class="btn btn-sm" href="borrowings.php">Open borrowing</a>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    <script>
    (function () {
        var labels = <?= json_encode($labels_short, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var entries = <?= json_encode($chart_logs_entries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var exits = <?= json_encode($chart_logs_exits, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var issued = <?= json_encode($chart_borrow_issued, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var returned = <?= json_encode($chart_borrow_returned, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var collAvail = <?= (int) $collection_available ?>;
        var collLoan = <?= (int) $collection_on_loan ?>;
        var collLost = <?= (int) $borrow_lost ?>;
        var roleLabels = <?= json_encode($user_role_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var roleCounts = <?= json_encode($user_role_counts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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
                    labels: {
                        boxWidth: 10,
                        boxHeight: 10,
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(10, 15, 24, .94)',
                    borderColor: 'rgba(255,255,255,.12)',
                    borderWidth: 1,
                    padding: 12,
                    titleMarginBottom: 8,
                    displayColors: true,
                    callbacks: {
                        label: function (ctx) {
                            var v = ctx.parsed;
                            if (ctx.parsed && typeof ctx.parsed === 'object' && ctx.parsed.y !== undefined) {
                                v = ctx.parsed.y;
                            }
                            if (typeof v !== 'number') {
                                v = ctx.raw;
                            }
                            if (typeof v !== 'number') {
                                v = 0;
                            }
                            var lab = ctx.dataset && ctx.dataset.label ? ctx.dataset.label + ': ' : '';
                            return lab + v;
                        }
                    }
                }
            }
        };

        var elLogs = document.getElementById('chartLogs');
        if (elLogs) {
            new Chart(elLogs, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Entries',
                            data: entries,
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34,197,94,.18)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                            pointHoverRadius: 4
                        },
                        {
                            label: 'Exits',
                            data: exits,
                            borderColor: '#38bdf8',
                            backgroundColor: 'rgba(56,189,248,.14)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                            pointHoverRadius: 4
                        }
                    ]
                },
                options: Object.assign({}, commonOpts, {
                    animation: { duration: 650, easing: 'easeOutQuart' },
                    elements: { line: { borderWidth: 2.5 }, point: { hoverBorderWidth: 2 } },
                    scales: {
                        x: { grid: { color: grid }, ticks: { maxRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elCirc = document.getElementById('chartCirculation');
        if (elCirc) {
            new Chart(elCirc, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Issued',
                            data: issued,
                            borderColor: '#a78bfa',
                            backgroundColor: 'rgba(167,139,250,.14)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                            pointHoverRadius: 5
                        },
                        {
                            label: 'Returned',
                            data: returned,
                            borderColor: '#f472b6',
                            backgroundColor: 'rgba(244,114,182,.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                            pointHoverRadius: 5
                        }
                    ]
                },
                options: Object.assign({}, commonOpts, {
                    animation: { duration: 650, easing: 'easeOutQuart' },
                    elements: { line: { borderWidth: 2.5 }, point: { hoverBorderWidth: 2 } },
                    scales: {
                        x: { grid: { color: grid }, ticks: { maxRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elColl = document.getElementById('chartCollection');
        if (elColl) {
            var collData = [collAvail, collLoan, collLost];
            new Chart(elColl, {
                type: 'doughnut',
                data: {
                    labels: ['Stock on shelves', 'Checked out (open loans)', 'Lost books'],
                    datasets: [{
                        data: collData,
                        backgroundColor: [
                            'rgba(45,212,191,.78)',
                            'rgba(124,58,237,.72)',
                            'rgba(245,158,11,.82)'
                        ],
                        borderColor: '#101726',
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: Object.assign({}, commonOpts, {
                    cutout: '58%',
                    plugins: Object.assign({}, commonOpts.plugins, {
                        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } },
                        tooltip: Object.assign({}, commonOpts.plugins.tooltip, {
                            callbacks: {
                                label: function (ctx) {
                                    var total = (ctx.dataset.data || []).reduce(function (a, b) { return a + b; }, 0);
                                    var v = ctx.parsed;
                                    var pct = total ? Math.round((v / total) * 1000) / 10 : 0;
                                    return ctx.label + ': ' + v + ' (' + pct + '%)';
                                }
                            }
                        })
                    })
                })
            });
        }

        var elBar = document.getElementById('chartBorrowBar');
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
                    animation: { duration: 550, easing: 'easeOutQuart' },
                    plugins: Object.assign({}, commonOpts.plugins, { legend: { display: false } }),
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                })
            });
        }

        var elR = document.getElementById('chartRoles');
        if (elR && roleLabels.length) {
            new Chart(elR, {
                type: 'bar',
                data: {
                    labels: roleLabels,
                    datasets: [{
                        label: 'Users',
                        data: roleCounts,
                        backgroundColor: 'rgba(56,189,248,.35)',
                        borderColor: 'rgba(56,189,248,.9)',
                        borderWidth: 1,
                        borderRadius: 6
                    }]
                },
                options: Object.assign({}, commonOpts, {
                    animation: { duration: 550, easing: 'easeOutQuart' },
                    indexAxis: 'y',
                    plugins: Object.assign({}, commonOpts.plugins, { legend: { display: false } }),
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } },
                        y: { grid: { display: false } }
                    }
                })
            });
        }
    })();
    </script>
</body>
</html>
