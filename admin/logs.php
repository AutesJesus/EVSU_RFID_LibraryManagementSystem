<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/portal_bootstrap.php';
require_once __DIR__ . '/../includes/ajax_response.php';
portal_bootstrap();

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
        $p = trim((string) $avatar_path);
        if (preg_match('#^https?://#i', $p) === 1 || str_starts_with($p, '//')) {
            return $p;
        }
        return app_public_path($p);
    }
    return ui_avatar_url($fallback_name);
}

$flash = '';
$error = '';

function qs(array $base, array $overrides = []): string
{
    $merged = array_merge($base, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') unset($merged[$k]);
    }
    $q = http_build_query($merged);
    return $q === '' ? '' : ('?' . $q);
}

function is_valid_date(string $s): bool
{
    if ($s === '') return false;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    return $dt !== false && $dt->format('Y-m-d') === $s;
}

function logs_qs(array $overrides = []): array
{
    global $q, $mode_f, $date_from, $date_to, $role_f, $dept_f, $cat_f;
    $merged = array_merge([
        'q' => $q,
        'mode' => $mode_f,
        'from' => $date_from,
        'to' => $date_to,
        'role' => $role_f,
        'dept' => $dept_f,
        'cat' => $cat_f,
    ], $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') {
            unset($merged[$k]);
        }
    }
    return $merged;
}

function log_is_unknown_rfid(array $row): bool
{
    $note = strtolower(trim((string) ($row['note'] ?? '')));
    return ($row['user_id'] ?? null) === null && $note === 'unknown_rfid';
}

function log_mode_badge(string $mode): string
{
    $m = strtolower(trim($mode));
    if ($m === 'entry') {
        return '<span class="pill ok">ENTRY</span>';
    }
    if ($m === 'exit') {
        return '<span class="pill bad">EXIT</span>';
    }
    return '<span class="pill">' . h(strtoupper($mode)) . '</span>';
}

function log_user_label(array $row): string
{
    if (log_is_unknown_rfid($row)) {
        return 'Unknown RFID';
    }
    $name = trim((string) ($row['full_name'] ?? ''));
    return $name !== '' ? $name : '—';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'edit') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $mode = isset($_POST['mode']) ? (string) $_POST['mode'] : 'entry';
            $rfid_tag = isset($_POST['rfid_tag']) ? trim((string) $_POST['rfid_tag']) : '';
            $scanned_at = isset($_POST['scanned_at']) ? trim((string) $_POST['scanned_at']) : '';
            $note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

            if ($id <= 0) {
                throw new RuntimeException('Missing log id.');
            }
            if (!in_array($mode, ['entry', 'exit'], true)) {
                throw new RuntimeException('Invalid mode.');
            }
            if ($rfid_tag === '') {
                throw new RuntimeException('RFID tag is required.');
            }
            if ($scanned_at === '') {
                throw new RuntimeException('Scanned at is required.');
            }

            $stmt = $pdo->prepare(
                'UPDATE entry_exit_logs
                 SET rfid_tag = :rfid_tag,
                     mode = :mode,
                     scanned_at = :scanned_at,
                     note = :note
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'rfid_tag' => $rfid_tag,
                'mode' => $mode,
                'scanned_at' => $scanned_at,
                'note' => ($note !== '') ? $note : null,
            ]);
            $flash = 'Log updated.';
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing log id.');
            }
            $stmt = $pdo->prepare('DELETE FROM entry_exit_logs WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $flash = 'Log deleted.';
        } elseif ($action === 'delete_bulk') {
            $rawIds = $_POST['ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new RuntimeException('No logs selected.');
            }
            $ids = [];
            foreach ($rawIds as $rawId) {
                $id = (int) $rawId;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if ($ids === []) {
                throw new RuntimeException('No logs selected.');
            }
            if (count($ids) > 500) {
                throw new RuntimeException('You can delete at most 500 logs at once.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare('DELETE FROM entry_exit_logs WHERE id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $n = $stmt->rowCount();
            $flash = $n . ' log' . ($n === 1 ? '' : 's') . ' deleted.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$mode_f = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';
$role_f = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : '';
$dept_f = isset($_GET['dept']) ? trim((string) $_GET['dept']) : '';
$cat_f = isset($_GET['cat']) ? strtolower(trim((string) $_GET['cat'])) : '';
$date_from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$date_to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$print = isset($_GET['print']) && (string)$_GET['print'] === '1';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if (!in_array($mode_f, ['', 'entry', 'exit'], true)) $mode_f = '';
if (!in_array($role_f, ['', 'student', 'faculty', 'librarian'], true)) $role_f = '';
if ($cat_f !== 'unknown') $cat_f = '';
if ($date_from !== '' && !is_valid_date($date_from)) $date_from = '';
if ($date_to !== '' && !is_valid_date($date_to)) $date_to = '';
if ($cat_f === 'unknown') {
    $role_f = '';
    $dept_f = '';
}

$edit_log = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare(
        'SELECT l.*, u.full_name, u.role, u.department
         FROM entry_exit_logs l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.id = :id'
    );
    $stmt->execute(['id' => $edit_id]);
    $edit_log = $stmt->fetch() ?: null;
}

$whereParts = [];
$params = [];
if ($q !== '') {
    $whereParts[] = '(l.rfid_tag LIKE :q OR u.full_name LIKE :q OR u.department LIKE :q OR u.role LIKE :q OR l.note LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($mode_f !== '') {
    $whereParts[] = 'l.mode = :mode';
    $params['mode'] = $mode_f;
}
if ($date_from !== '') {
    $whereParts[] = 'DATE(l.scanned_at) >= :from';
    $params['from'] = $date_from;
}
if ($date_to !== '') {
    $whereParts[] = 'DATE(l.scanned_at) <= :to';
    $params['to'] = $date_to;
}
if ($cat_f === 'unknown') {
    $whereParts[] = "(l.user_id IS NULL AND l.note = 'unknown_rfid')";
} elseif ($role_f !== '') {
    $whereParts[] = 'u.role = :role';
    $params['role'] = $role_f;
}
if ($dept_f !== '' && $cat_f !== 'unknown') {
    $whereParts[] = 'u.department = :dept';
    $params['dept'] = $dept_f;
}

$dept_options = [];
try {
    $deptStmt = $pdo->query(
        "SELECT DISTINCT u.department
         FROM entry_exit_logs l
         INNER JOIN users u ON u.id = l.user_id
         WHERE u.department IS NOT NULL AND TRIM(u.department) <> ''
         ORDER BY u.department ASC"
    );
    $dept_options = array_map('strval', $deptStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
} catch (Throwable $e) {
    $dept_options = [];
}

$where = '';
if ($whereParts) {
    $where = 'WHERE ' . implode(' AND ', $whereParts);
}

$stmt = $pdo->prepare(
    "SELECT l.*, u.full_name, u.role, u.department
     FROM entry_exit_logs l
     LEFT JOIN users u ON u.id = l.user_id
     {$where}
     ORDER BY l.scanned_at DESC, l.id DESC
     LIMIT 500"
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Base querystring for links (filters preserved)
$baseQs = logs_qs();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entry/Exit Logs — Admin</title>
    <link rel="stylesheet" href="<?= h(portal_asset('assets/admin.css')) ?>">
</head>
<body>
<?php if ($print): ?>
    <main class="admin-main">
        <div class="container">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin: 0 0 12px; flex-wrap:wrap;">
                <div>
                    <div style="font-weight:950; font-size: 1.15rem;">Entry/Exit Logs</div>
                    <div class="muted" style="margin-top:6px;">
                        Filters:
                        <?= $q !== '' ? ' q="' . h($q) . '"' : ' all' ?>
                        <?= $mode_f !== '' ? ' · mode=' . h($mode_f) : '' ?>
                        <?= $cat_f === 'unknown' ? ' · unknown RFID' : ($role_f !== '' ? ' · role=' . h($role_f) : '') ?>
                        <?= $dept_f !== '' ? ' · dept=' . h($dept_f) : '' ?>
                        <?= $date_from !== '' ? ' · from=' . h($date_from) : '' ?>
                        <?= $date_to !== '' ? ' · to=' . h($date_to) : '' ?>
                    </div>
                </div>
                <div class="actions">
                    <a class="btn btn-ghost" href="logs.php<?= h(qs($baseQs)) ?>">Back</a>
                    <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
                </div>
            </div>

            <section class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Scanned at</th>
                                <th>Mode</th>
                                <th>RFID</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Dept</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="8" class="muted">No logs found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr<?= log_is_unknown_rfid($l) ? ' class="log-row-unknown"' : '' ?>>
                                    <td><?= (int)$l['id'] ?></td>
                                    <td><?= h((string)$l['scanned_at']) ?></td>
                                    <td><?= log_mode_badge((string)$l['mode']) ?></td>
                                    <td><?= h((string)$l['rfid_tag']) ?></td>
                                    <td><?= h(log_user_label($l)) ?></td>
                                    <td><?= h((string)($l['role'] ?? '')) ?></td>
                                    <td><?= h((string)($l['department'] ?? '')) ?></td>
                                    <td><?= h((string)($l['note'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
    <script>window.addEventListener('load', () => { window.print(); });</script>
</body>
</html>
<?php exit; ?>
<?php endif; ?>
    <div class="admin-shell">
        <?php require __DIR__ . '/../includes/portal_sidebar.php'; ?>

        <main class="admin-main admin-page-list">
            <div class="container">
                <header class="admin-topbar">
                    <div>
                        <h1>Entry/Exit Logs</h1>
                        <div class="subtitle">View, search, edit, and delete scans (latest 500).</div>
                    </div>
                </header>

                <p id="ajaxFlash" class="msg" style="display:none;" role="status"></p>
                <?php if ($flash !== ''): ?>
                    <p class="msg ok" role="status"><?= h($flash) ?></p>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <p class="msg err" role="alert"><?= h($error) ?></p>
                <?php endif; ?>

                <div class="grid directory-list-grid">
                    <section class="card inventory-card directory-list-card logs-list-card" aria-label="Logs list" id="logsListCard">
                    <div class="card-body inventory-toolbar directory-list-toolbar logs-toolbar-shell" id="logsToolbarShell">

                        <div class="logs-toolbar-toggle-bar">
                            <button
                                type="button"
                                class="btn btn-sm btn-ghost logs-toggle-tools-btn"
                                id="logsToggleTools"
                                aria-expanded="true"
                                aria-controls="logsToolsPanel"
                            >
                                <svg class="btn-ico logs-toggle-tools-ico" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </button>
                            <span class="muted logs-toolbar-toggle-hint" id="logsToggleToolsHint">Show list only</span>
                        </div>

                        <div id="logsToolsPanel" class="logs-tools-panel">
                        <form method="get" action="" class="control-bar" role="search" aria-label="Logs controls">
                            <div class="inventory-search-wrap">
                                <span class="inventory-search-ico" aria-hidden="true">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M21 21l-4.3-4.3"/>
                                        <circle cx="11" cy="11" r="7"/>
                                    </svg>
                                </span>
                                <input
                                    id="logsSearch"
                                    class="inventory-search"
                                    name="q"
                                    placeholder="Search name, RFID, dept, or note"
                                    value="<?= h($q) ?>"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="control-right" aria-label="Filters">
                                <input id="from" name="from" type="date" value="<?= h($date_from) ?>" aria-label="From date">
                                <input id="to" name="to" type="date" value="<?= h($date_to) ?>" aria-label="To date">
                                
                                <button class="btn btn-primary" type="submit">Apply</button>

                                <a class="btn btn-ghost" href="logs.php<?= h(qs($baseQs, ['print' => '1'])) ?>" target="_blank" rel="noopener">Print</a>
                                <a class="btn btn-ghost" href="logs.php<?= h(qs($baseQs, ['print' => '1'])) ?>" target="_blank" rel="noopener">Print filtered</a>
                                <a
                                    id="logsClear"
                                    class="btn btn-ghost inventory-clear<?= ($q === '' && $date_from === '' && $date_to === '' && $mode_f === '' && $role_f === '' && $dept_f === '' && $cat_f === '') ? ' is-hidden' : '' ?>"
                                    href="logs.php"
                                >Clear</a>
                            </div>
                        </form>

                        <nav class="inventory-tabs logs-toolbar-tabs" aria-label="Log filters">
                            <?php
                                $mkRole = function (string $label, string $v) use ($role_f, $cat_f): void {
                                    $qs = logs_qs(['role' => $v, 'cat' => '']);
                                    $is = $cat_f === '' && (($v === '' && $role_f === '') || ($role_f === $v));
                                    $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                    echo '<a class="' . $cls . '" href="logs.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                };
                                $mkMode = function (string $label, string $v) use ($mode_f): void {
                                    $qs = logs_qs(['mode' => $v]);
                                    $is = ($v === '' && $mode_f === '') || ($mode_f === $v);
                                    $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                    echo '<a class="' . $cls . '" href="logs.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                };
                                $unknownQs = logs_qs(['cat' => 'unknown', 'role' => '', 'dept' => '']);
                                $unknownActive = $cat_f === 'unknown';
                            ?>
                            <span class="muted borrow-toolbar-divider" aria-hidden="true">Role</span>
                            <?php
                                $mkRole('All', '');
                                $mkRole('Student', 'student');
                                $mkRole('Faculty', 'faculty');
                                $mkRole('Librarian', 'librarian');
                            ?>
                            <span class="muted borrow-toolbar-divider" aria-hidden="true">Mode</span>
                            <?php
                                $mkMode('All', '');
                                $mkMode('Entry', 'entry');
                                $mkMode('Exit', 'exit');
                            ?>
                            <div class="logs-toolbar-end">
                                <span class="muted borrow-toolbar-divider" aria-hidden="true">Department</span>
                                <form method="get" action="logs.php" class="logs-dept-form" id="logsDeptForm">
                                    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>
                                    <?php if ($mode_f !== ''): ?><input type="hidden" name="mode" value="<?= h($mode_f) ?>"><?php endif; ?>
                                    <?php if ($role_f !== ''): ?><input type="hidden" name="role" value="<?= h($role_f) ?>"><?php endif; ?>
                                    <?php if ($cat_f !== ''): ?><input type="hidden" name="cat" value="<?= h($cat_f) ?>"><?php endif; ?>
                                    <?php if ($date_from !== ''): ?><input type="hidden" name="from" value="<?= h($date_from) ?>"><?php endif; ?>
                                    <?php if ($date_to !== ''): ?><input type="hidden" name="to" value="<?= h($date_to) ?>"><?php endif; ?>
                                    <label class="sr-only" for="logsDept">Department</label>
                                    <select id="logsDept" class="inventory-filter-select logs-dept-select" name="dept"<?= $cat_f === 'unknown' ? ' disabled' : '' ?>>
                                        <option value="">All departments</option>
                                        <?php foreach ($dept_options as $deptOpt): ?>
                                            <option value="<?= h($deptOpt) ?>"<?= $dept_f === $deptOpt ? ' selected' : '' ?>><?= h($deptOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a class="<?= $unknownActive ? 'btn btn-sm btn-primary' : 'btn btn-sm' ?>" href="logs.php?<?= h(http_build_query($unknownQs)) ?>">Unknown RFID</a>
                            </div>
                        </nav>

                        <div class="logs-bulk-bar" id="logsBulkBar" aria-label="Bulk actions">
                            <label class="logs-bulk-check">
                                <input type="checkbox" id="logsSelectAll" aria-label="Select all logs on this page">
                                <span>Select all on page</span>
                            </label>
                            <span class="muted logs-bulk-count" id="logsBulkCount">0 selected</span>
                            <button type="button" class="btn btn-sm btn-danger" id="logsDeleteSelected" disabled>Delete selected</button>
                        </div>
                        </div><!-- /#logsToolsPanel -->

                    </div>

                    <div class="table-wrap directory-list-scroll">
                        <table class="directory-data-table">
                            <thead>
                                <tr>
                                    <th class="logs-col-check" scope="col">
                                        <span class="sr-only">Select</span>
                                    </th>
                                    <th>ID</th>
                                    <th>Scanned at</th>
                                    <th>Mode</th>
                                    <th>RFID</th>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Dept</th>
                                    <th>Note</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$logs): ?>
                                <tr><td colspan="10" class="muted">No logs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l): ?>
                                    <tr<?= log_is_unknown_rfid($l) ? ' class="log-row-unknown"' : '' ?> data-log-id="<?= (int)$l['id'] ?>">
                                        <td class="logs-col-check">
                                            <input
                                                type="checkbox"
                                                class="logs-row-check"
                                                value="<?= (int)$l['id'] ?>"
                                                aria-label="Select log #<?= (int)$l['id'] ?>"
                                            >
                                        </td>
                                        <td><?= (int)$l['id'] ?></td>
                                        <td><?= h((string)$l['scanned_at']) ?></td>
                                        <td><?= log_mode_badge((string)$l['mode']) ?></td>
                                        <td><?= h((string)$l['rfid_tag']) ?></td>
                                        <td>
                                            <?= h(log_user_label($l)) ?>
                                            <?php if (log_is_unknown_rfid($l)): ?>
                                                <span class="pill warn" style="margin-left:6px;">Unregistered</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h((string)($l['role'] ?? '')) ?></td>
                                        <td><?= h((string)($l['department'] ?? '')) ?></td>
                                        <td><?= h((string)($l['note'] ?? '')) ?></td>
                                        <td>
                                            <div class="actions">
                                                <button
                                                    class="btn btn-sm"
                                                    type="button"
                                                    data-action="edit"
                                                    data-id="<?= (int)$l['id'] ?>"
                                                    data-rfid="<?= h((string)$l['rfid_tag']) ?>"
                                                    data-mode="<?= h((string)$l['mode']) ?>"
                                                    data-scanned="<?= h((string)$l['scanned_at']) ?>"
                                                    data-note="<?= h((string)($l['note'] ?? '')) ?>"
                                                    data-user="<?= h((string)($l['full_name'] ?? '—')) ?>"
                                                    data-role="<?= h((string)($l['role'] ?? '')) ?>"
                                                    onclick="openEdit(this)"
                                                >Edit</button>

                                                <button
                                                    class="btn btn-sm btn-danger"
                                                    type="button"
                                                    data-id="<?= (int)$l['id'] ?>"
                                                    data-scanned="<?= h((string)$l['scanned_at']) ?>"
                                                    data-rfid="<?= h((string)$l['rfid_tag']) ?>"
                                                    onclick="openDelete(this)"
                                                >Delete</button>
                                            </div>
                                        </td>
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

    <!-- Edit modal -->
    <div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
        <div class="modal-panel">
            <div class="modal-header">
                <h3 id="editTitle" class="modal-title">Edit log</h3>
                <button class="icon-btn" type="button" onclick="closeEdit()" aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="hint" id="editLinkedUser">—</div>
                <form method="post" action="" id="editLogForm">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id" value="">

                    <label for="edit_rfid">RFID tag</label>
                    <input id="edit_rfid" name="rfid_tag" required value="">

                    <label for="edit_mode">Mode</label>
                    <select id="edit_mode" name="mode" required>
                        <option value="entry">Entry</option>
                        <option value="exit">Exit</option>
                    </select>

                    <label for="edit_scanned">Scanned at (YYYY-MM-DD HH:MM:SS)</label>
                    <input id="edit_scanned" name="scanned_at" required value="">

                    <label for="edit_note">Note</label>
                    <input id="edit_note" name="note" value="">

                    <div class="modal-footer">
                        <button class="btn btn-ghost" type="button" onclick="closeEdit()">Cancel</button>
                        <button class="btn btn-primary" type="submit">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete modal (single or bulk) -->
    <div id="deleteModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="delTitle">
        <div class="modal-panel" style="width:min(560px,96vw);">
            <div class="modal-header">
                <h3 id="delTitle" class="modal-title">Delete log?</h3>
                <button class="icon-btn" type="button" onclick="closeDelete()" aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p class="muted" id="delSummary" style="margin:0 0 12px;">—</p>
                <p class="muted" id="delHint" style="margin:0;">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" onclick="closeDelete()">Cancel</button>
                <button class="btn btn-danger" type="button" id="delConfirmBtn" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script src="../assets/app_ajax.js"></script>
    <script>
        const logsSearch = document.getElementById('logsSearch');
        const logsClear = document.getElementById('logsClear');
        const logsToolbarShell = document.getElementById('logsToolbarShell');
        const logsToggleTools = document.getElementById('logsToggleTools');
        const logsToggleToolsLabel = document.getElementById('logsToggleToolsLabel');
        const logsToggleToolsHint = document.getElementById('logsToggleToolsHint');
        const logsToolsPanel = document.getElementById('logsToolsPanel');
        const logsListCard = document.getElementById('logsListCard');
        const LOGS_TOOLS_STORAGE_KEY = 'evsu_logs_tools_hidden';

        function setLogsToolsCollapsed(collapsed) {
            if (!logsToolbarShell) return;
            logsToolbarShell.classList.toggle('logs-tools-collapsed', collapsed);
            if (logsListCard) {
                logsListCard.classList.toggle('logs-list-expanded', collapsed);
            }
            if (logsToggleTools) {
                logsToggleTools.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            }
            if (logsToggleToolsLabel) {
                logsToggleToolsLabel.textContent = collapsed ? 'Show filters' : 'Hide filters';
            }
            if (logsToggleToolsHint) {
                logsToggleToolsHint.textContent = collapsed ? 'Filters hidden — list expanded' : 'Show list only';
            }
            try {
                localStorage.setItem(LOGS_TOOLS_STORAGE_KEY, collapsed ? '1' : '0');
            } catch (e) {}
        }

        if (logsToggleTools && logsToolbarShell) {
            var logsToolsInitiallyHidden = false;
            try {
                logsToolsInitiallyHidden = localStorage.getItem(LOGS_TOOLS_STORAGE_KEY) === '1';
            } catch (e) {}
            setLogsToolsCollapsed(logsToolsInitiallyHidden);
            logsToggleTools.addEventListener('click', function () {
                setLogsToolsCollapsed(!logsToolbarShell.classList.contains('logs-tools-collapsed'));
            });
        }

        function showAjaxFlash(text, isErr) {
            if (window.showActionMessage) {
                window.showActionMessage(text, isErr);
                if (isErr && editModal && editModal.classList.contains('is-open') && window.adminShakeModal) {
                    window.adminShakeModal(editModal);
                }
                return;
            }
            var msg = String(text || '').trim();
            if (!msg) return;
            if (window.uiToast) {
                window.uiToast(isErr ? 'error' : 'success', msg);
            }
            var el = document.getElementById('ajaxFlash');
            if (el) {
                el.textContent = msg;
                el.className = 'msg ' + (isErr ? 'err' : 'ok');
                el.style.display = '';
                el.setAttribute('role', isErr ? 'alert' : 'status');
            }
            if (isErr && editModal && editModal.classList.contains('is-open') && window.adminShakeModal) {
                window.adminShakeModal(editModal);
            }
        }

        var editLogForm = document.getElementById('editLogForm');
        if (editLogForm && window.ajaxPostForm) {
            editLogForm.addEventListener('submit', function (e) {
                e.preventDefault();
                ajaxPostForm(editLogForm).then(function (data) {
                    if (data && data.ok) {
                        if (window.ajaxReloadOnSuccess) window.ajaxReloadOnSuccess(data);
                        else window.location.reload();
                    } else {
                        showAjaxFlash((data && (data.message || data.error)) || 'Error', true);
                    }
                }).catch(function () { showAjaxFlash('Network error.', true); });
            });
        }
        function syncLogsClearVisibility() {
            if (!logsSearch || !logsClear) return;
            const hasText = String(logsSearch.value || '').trim().length > 0;
            const from = document.getElementById('from');
            const to = document.getElementById('to');
            const hasDates = Boolean((from && String(from.value || '').trim()) || (to && String(to.value || '').trim()));
            const shouldShow = hasText || hasDates || <?= json_encode($mode_f !== '' || $role_f !== '' || $dept_f !== '' || $cat_f !== '') ?>;
            logsClear.classList.toggle('is-hidden', !shouldShow);
            logsClear.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        }
        if (logsSearch) {
            logsSearch.addEventListener('input', syncLogsClearVisibility);
            syncLogsClearVisibility();
        }
        const fromEl = document.getElementById('from');
        const toEl = document.getElementById('to');
        if (fromEl) fromEl.addEventListener('change', syncLogsClearVisibility);
        if (toEl) toEl.addEventListener('change', syncLogsClearVisibility);

        const logsDept = document.getElementById('logsDept');
        const logsDeptForm = document.getElementById('logsDeptForm');
        if (logsDept && logsDeptForm) {
            logsDept.addEventListener('change', function () {
                if (logsDept.disabled) return;
                logsDeptForm.submit();
            });
        }

        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        const delTitle = document.getElementById('delTitle');
        const delHint = document.getElementById('delHint');
        const logsSelectAll = document.getElementById('logsSelectAll');
        const logsDeleteSelected = document.getElementById('logsDeleteSelected');
        const logsBulkCount = document.getElementById('logsBulkCount');
        let pendingDeleteId = null;
        let pendingDeleteIds = [];

        function getRowChecks() {
            return Array.prototype.slice.call(document.querySelectorAll('.logs-row-check'));
        }

        function getSelectedIds() {
            return getRowChecks()
                .filter(function (cb) { return cb.checked; })
                .map(function (cb) { return parseInt(cb.value, 10); })
                .filter(function (id) { return id > 0; });
        }

        function syncBulkUi() {
            var checks = getRowChecks();
            var selected = getSelectedIds();
            var n = selected.length;
            var total = checks.length;

            if (logsBulkCount) {
                logsBulkCount.textContent = n + ' selected' + (total ? (' of ' + total) : '');
            }
            if (logsDeleteSelected) {
                logsDeleteSelected.disabled = n === 0;
                logsDeleteSelected.textContent = n > 0 ? ('Delete selected (' + n + ')') : 'Delete selected';
            }
            if (logsSelectAll) {
                logsSelectAll.indeterminate = n > 0 && n < total;
                logsSelectAll.checked = total > 0 && n === total;
            }

            checks.forEach(function (cb) {
                var row = cb.closest('tr');
                if (!row) return;
                row.classList.toggle('log-row-selected', cb.checked);
            });
        }

        if (logsSelectAll) {
            logsSelectAll.addEventListener('change', function () {
                var on = logsSelectAll.checked;
                getRowChecks().forEach(function (cb) { cb.checked = on; });
                syncBulkUi();
            });
        }

        getRowChecks().forEach(function (cb) {
            cb.addEventListener('change', syncBulkUi);
        });

        if (logsDeleteSelected) {
            logsDeleteSelected.addEventListener('click', function () {
                var ids = getSelectedIds();
                if (!ids.length) return;
                pendingDeleteId = null;
                pendingDeleteIds = ids.slice();
                if (delTitle) delTitle.textContent = ids.length === 1 ? 'Delete log?' : ('Delete ' + ids.length + ' logs?');
                document.getElementById('delSummary').textContent =
                    ids.length === 1
                        ? ('Log #' + ids[0])
                        : ('You are about to delete ' + ids.length + ' logs from this page.');
                if (delHint) delHint.textContent = 'This action cannot be undone.';
                deleteModal.classList.add('is-open');
            });
        }

        syncBulkUi();

        function openEdit(btn){
            document.getElementById('edit_id').value = btn.dataset.id || '';
            document.getElementById('edit_rfid').value = btn.dataset.rfid || '';
            document.getElementById('edit_mode').value = btn.dataset.mode || 'entry';
            document.getElementById('edit_scanned').value = btn.dataset.scanned || '';
            document.getElementById('edit_note').value = btn.dataset.note || '';
            const user = (btn.dataset.user || '—').trim();
            const role = (btn.dataset.role || '').trim();
            document.getElementById('editLinkedUser').textContent = 'Linked user: ' + user + (role ? (' (' + role + ')') : '');
            editModal.classList.add('is-open');
            document.getElementById('edit_rfid').focus();
        }
        function closeEdit(){ editModal.classList.remove('is-open'); }

        function openDelete(btn){
            pendingDeleteIds = [];
            pendingDeleteId = btn.getAttribute('data-id') || '';
            const id = btn.dataset.id || '';
            const scanned = btn.dataset.scanned || '';
            const rfid = btn.dataset.rfid || '';
            if (delTitle) delTitle.textContent = 'Delete log?';
            document.getElementById('delSummary').textContent = 'Log #' + id + ' — ' + scanned + (rfid ? (' — RFID ' + rfid) : '');
            if (delHint) delHint.textContent = 'This action cannot be undone.';
            deleteModal.classList.add('is-open');
        }
        function closeDelete(){
            deleteModal.classList.remove('is-open');
            pendingDeleteId = null;
            pendingDeleteIds = [];
        }
        function confirmDelete(){
            var fd = new FormData();
            fd.set('__ajax', '1');
            if (pendingDeleteIds.length) {
                fd.set('action', 'delete_bulk');
                pendingDeleteIds.forEach(function (id) {
                    fd.append('ids[]', String(id));
                });
            } else if (pendingDeleteId) {
                fd.set('action', 'delete');
                fd.set('id', String(pendingDeleteId));
            } else {
                return;
            }
            var postDelete = window.ajaxPostFd
                ? window.ajaxPostFd(window.location.pathname, fd)
                : fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' },
                }).then(function (res) { return res.json(); });

            postDelete.then(function (data) {
                if (data && data.ok) {
                    if (window.ajaxReloadOnSuccess) window.ajaxReloadOnSuccess(data);
                    else window.location.reload();
                } else {
                    showAjaxFlash((data && (data.message || data.error)) || 'Error', true);
                }
            }).catch(function () { showAjaxFlash('Network error.', true); });
            closeDelete();
        }

        // Close on ESC / backdrop click
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape'){
                if (editModal.classList.contains('is-open')) closeEdit();
                if (deleteModal.classList.contains('is-open')) closeDelete();
            }
        });
        [editModal, deleteModal].forEach(m => {
            m.addEventListener('click', (e) => {
                if (e.target === m){
                    if (m === editModal) closeEdit();
                    if (m === deleteModal) closeDelete();
                }
            });
        });

        // Back-compat: if ?edit= is used, open modal on load.
        <?php if ($edit_log): ?>
        window.addEventListener('load', () => {
            openEdit({
                dataset: {
                    id: "<?= (int)$edit_log['id'] ?>",
                    rfid: "<?= h((string)$edit_log['rfid_tag']) ?>",
                    mode: "<?= h((string)$edit_log['mode']) ?>",
                    scanned: "<?= h((string)$edit_log['scanned_at']) ?>",
                    note: "<?= h((string)($edit_log['note'] ?? '')) ?>",
                    user: "<?= h((string)($edit_log['full_name'] ?? '—')) ?>",
                    role: "<?= h((string)($edit_log['role'] ?? '')) ?>",
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>

