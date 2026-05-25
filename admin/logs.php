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
$date_from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
$date_to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';
$print = isset($_GET['print']) && (string)$_GET['print'] === '1';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if (!in_array($mode_f, ['', 'entry', 'exit'], true)) $mode_f = '';
if ($date_from !== '' && !is_valid_date($date_from)) $date_from = '';
if ($date_to !== '' && !is_valid_date($date_to)) $date_to = '';

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
$baseQs = [
    'q' => $q,
    'mode' => $mode_f,
    'from' => $date_from,
    'to' => $date_to,
];

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
                                <tr>
                                    <td><?= (int)$l['id'] ?></td>
                                    <td><?= h((string)$l['scanned_at']) ?></td>
                                    <td><?= h(strtoupper((string)$l['mode'])) ?></td>
                                    <td><?= h((string)$l['rfid_tag']) ?></td>
                                    <td><?= h((string)($l['full_name'] ?? '—')) ?></td>
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
                    <section class="card inventory-card directory-list-card" aria-label="Logs list">
                    <div class="card-body inventory-toolbar directory-list-toolbar">
                        <div class="card-header-bar">
                            <h2 class="card-title inventory-title" style="margin:0;">Logs</h2>
                            <div class="admin-actions" aria-label="Print actions">
                                <a class="btn btn-ghost" href="logs.php<?= h(qs($baseQs, ['print' => '1'])) ?>" target="_blank" rel="noopener">Print</a>
                                <a class="btn btn-ghost" href="logs.php<?= h(qs($baseQs, ['print' => '1'])) ?>" target="_blank" rel="noopener">Print filtered</a>
                            </div>
                        </div>

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
                                <a
                                    id="logsClear"
                                    class="btn btn-ghost inventory-clear<?= ($q === '' && $date_from === '' && $date_to === '' && $mode_f === '') ? ' is-hidden' : '' ?>"
                                    href="logs.php"
                                >Clear</a>
                            </div>
                        </form>

                        <nav class="inventory-tabs" aria-label="Log mode filters">
                            <?php
                                $mkMode = function (string $label, string $v) use ($q, $date_from, $date_to, $mode_f): void {
                                    $qs = [];
                                    if ($q !== '') $qs['q'] = $q;
                                    if ($date_from !== '') $qs['from'] = $date_from;
                                    if ($date_to !== '') $qs['to'] = $date_to;
                                    if ($v !== '') $qs['mode'] = $v;
                                    $is = ($v === '' && $mode_f === '') || ($mode_f === $v);
                                    $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                    echo '<a class="' . $cls . '" href="logs.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                };
                                $mkMode('All', '');
                                $mkMode('Entry', 'entry');
                                $mkMode('Exit', 'exit');
                            ?>
                        </nav>
                    </div>

                    <div class="table-wrap directory-list-scroll">
                        <table class="directory-data-table">
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$logs): ?>
                                <tr><td colspan="9" class="muted">No logs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l): ?>
                                    <tr>
                                        <td><?= (int)$l['id'] ?></td>
                                        <td><?= h((string)$l['scanned_at']) ?></td>
                                        <td><?= h(strtoupper((string)$l['mode'])) ?></td>
                                        <td><?= h((string)$l['rfid_tag']) ?></td>
                                        <td><?= h((string)($l['full_name'] ?? '—')) ?></td>
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

    <!-- Delete modal -->
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
                <p class="muted" style="margin:0;">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" onclick="closeDelete()">Cancel</button>
                <button class="btn btn-danger" type="button" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    <script src="../assets/app_ajax.js"></script>
    <script>
        const logsClear = document.getElementById('logsClear');

        function showAjaxFlash(text, isErr) {
            var el = document.getElementById('ajaxFlash');
            if (!el || !text) return;
            el.textContent = text;
            el.className = 'msg ' + (isErr ? 'err' : 'ok');
            el.style.display = '';
            el.setAttribute('role', isErr ? 'alert' : 'status');
        }

        var editLogForm = document.getElementById('editLogForm');
        if (editLogForm && window.ajaxPostForm) {
            editLogForm.addEventListener('submit', function (e) {
                e.preventDefault();
                ajaxPostForm(editLogForm).then(function (data) {
                    if (data.ok) window.location.reload();
                    else showAjaxFlash(data.message || data.error || 'Error', true);
                }).catch(function () { showAjaxFlash('Network error.', true); });
            });
        }
        function syncLogsClearVisibility() {
            if (!logsSearch || !logsClear) return;
            const hasText = String(logsSearch.value || '').trim().length > 0;
            const from = document.getElementById('from');
            const to = document.getElementById('to');
            const hasDates = Boolean((from && String(from.value || '').trim()) || (to && String(to.value || '').trim()));
            const shouldShow = hasText || hasDates || <?= json_encode($mode_f !== '') ?>;
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

        const editModal = document.getElementById('editModal');
        const deleteModal = document.getElementById('deleteModal');
        let pendingDeleteId = null;

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
            pendingDeleteId = btn.getAttribute('data-id') || '';
            const id = btn.dataset.id || '';
            const scanned = btn.dataset.scanned || '';
            const rfid = btn.dataset.rfid || '';
            document.getElementById('delSummary').textContent = 'Log #' + id + ' — ' + scanned + (rfid ? (' — RFID ' + rfid) : '');
            deleteModal.classList.add('is-open');
        }
        function closeDelete(){ deleteModal.classList.remove('is-open'); pendingDeleteId = null; }
        function confirmDelete(){
            if (!pendingDeleteId) return;
            var f = document.createElement('form');
            f.method = 'post';
            f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + String(pendingDeleteId) + '">';
            document.body.appendChild(f);
            var fd = new FormData(f);
            fd.set('__ajax', '1');
            document.body.removeChild(f);
            fetch(window.location.pathname, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            }).then(function (res) { return res.json(); }).then(function (data) {
                if (data && data.ok) window.location.reload();
                else showAjaxFlash((data && (data.message || data.error)) || 'Error', true);
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

