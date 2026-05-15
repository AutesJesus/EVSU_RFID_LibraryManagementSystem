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

function dt_local_default_due(): string
{
    $d = new DateTimeImmutable('now');
    $d = $d->modify('+7 days');
    return $d->format('Y-m-d\TH:i');
}

function fmt_short_dt(?string $s): string
{
    if ($s === null || trim($s) === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($s))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return $s;
    }
}

function append_borrow_note(?string $current, string $line): string
{
    $base = trim((string) $current);
    $stamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    $entry = '[' . $stamp . '] ' . $line;
    return $base === '' ? $entry : ($base . "\n" . $entry);
}

/** Normalize Y-m-d from query string, or empty if invalid. */
function borrow_parse_ymd(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if ($dt === false || $dt->format('Y-m-d') !== $s) {
        return '';
    }
    return $s;
}

/**
 * @param array<string, string|int> $overrides
 * @return array<string, string>
 */
function borrowings_qs(array $overrides = []): array
{
    global $q, $view, $role_f, $date_from, $date_to;
    $qs = [];
    if ($q !== '') {
        $qs['q'] = $q;
    }
    if ($view !== '' && $view !== 'all') {
        $qs['view'] = $view;
    }
    if ($role_f !== '') {
        $qs['role'] = $role_f;
    }
    if ($date_from !== '') {
        $qs['date_from'] = $date_from;
    }
    if ($date_to !== '') {
        $qs['date_to'] = $date_to;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = (string) $v;
        }
    }
    return $qs;
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'issue') {
            $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
            $due_local = isset($_POST['due_at']) ? trim((string) $_POST['due_at']) : '';
            $note = isset($_POST['note']) ? trim((string) $_POST['note']) : '';

            if ($user_id <= 0 || $book_id <= 0) {
                throw new RuntimeException('Select a user and a book.');
            }

            $due_at = null;
            if ($due_local !== '') {
                $due_dt = DateTime::createFromFormat('Y-m-d\TH:i', $due_local);
                if ($due_dt === false) {
                    throw new RuntimeException('Invalid due date/time.');
                }
                $due_at = $due_dt->format('Y-m-d H:i:s');
            }

            $pdo->beginTransaction();

            $stmtU = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
            $stmtU->execute(['id' => $user_id]);
            $u = $stmtU->fetch();
            if ($u === false) {
                throw new RuntimeException('User not found.');
            }
            if ((string)$u['status'] !== 'active') {
                throw new RuntimeException('User is inactive.');
            }

            $stmtB = $pdo->prepare('SELECT id, title, status, copies_available FROM books WHERE id = :id FOR UPDATE');
            $stmtB->execute(['id' => $book_id]);
            $b = $stmtB->fetch();
            if ($b === false) {
                throw new RuntimeException('Book not found.');
            }
            if ((string)$b['status'] !== 'active') {
                throw new RuntimeException('Book is archived.');
            }
            if ((int)$b['copies_available'] <= 0) {
                throw new RuntimeException('No copies available for this book.');
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO borrowings (user_id, book_id, due_at, status, note)
                 VALUES (:user_id, :book_id, :due_at, :status, :note)'
            );
            $stmtIns->execute([
                'user_id' => $user_id,
                'book_id' => $book_id,
                'due_at' => $due_at,
                'status' => 'borrowed',
                'note' => $note !== '' ? $note : null,
            ]);

            $stmtDec = $pdo->prepare('UPDATE books SET copies_available = copies_available - 1 WHERE id = :id');
            $stmtDec->execute(['id' => $book_id]);

            $pdo->commit();
            $flash = 'Borrowing issued.';
        } elseif ($action === 'return') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing borrowing id.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, book_id, status
                 FROM borrowings
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $br = $stmt->fetch();
            if ($br === false) {
                throw new RuntimeException('Borrowing not found.');
            }
            if ((string)$br['status'] !== 'borrowed') {
                throw new RuntimeException('Borrowing is not currently borrowed.');
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE borrowings
                 SET status = 'returned', returned_at = NOW()
                 WHERE id = :id"
            );
            $stmtUpd->execute(['id' => $id]);

            $stmtInc = $pdo->prepare(
                'UPDATE books
                 SET copies_available = LEAST(copies_total, copies_available + 1)
                 WHERE id = :id'
            );
            $stmtInc->execute(['id' => (int)$br['book_id']]);

            $pdo->commit();
            $flash = 'Book returned.';
        } elseif ($action === 'mark_lost') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing borrowing id.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, status, note
                 FROM borrowings
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $br = $stmt->fetch();
            if ($br === false) {
                throw new RuntimeException('Borrowing not found.');
            }
            if ((string)$br['status'] !== 'borrowed') {
                throw new RuntimeException('Borrowing is not currently borrowed.');
            }

            $newNote = append_borrow_note((string) ($br['note'] ?? ''), 'Marked as lost.');

            $stmtUpd = $pdo->prepare(
                "UPDATE borrowings
                 SET status = 'lost', lost_at = NOW(), note = :note
                 WHERE id = :id"
            );
            $stmtUpd->execute(['id' => $id, 'note' => $newNote]);

            $pdo->commit();
            $flash = 'Marked as lost.';
        } elseif ($action === 'resolve_lost_returned') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $detail_note = isset($_POST['detail_note']) ? trim((string) $_POST['detail_note']) : '';
            if ($id <= 0) {
                throw new RuntimeException('Missing borrowing id.');
            }
            if ($detail_note !== '' && strlen($detail_note) > 500) {
                throw new RuntimeException('Note is too long (max 500 characters).');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, book_id, status, note
                 FROM borrowings
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $br = $stmt->fetch();
            if ($br === false) {
                throw new RuntimeException('Borrowing not found.');
            }
            if ((string) $br['status'] !== 'lost') {
                throw new RuntimeException('This record is not marked as lost.');
            }

            $line = 'Lost case closed: physical copy returned to shelf.';
            if ($detail_note !== '') {
                $line .= ' Note: ' . $detail_note;
            }
            $newNote = append_borrow_note((string) ($br['note'] ?? ''), $line);

            $stmtUpd = $pdo->prepare(
                "UPDATE borrowings
                 SET status = 'returned', returned_at = NOW(), note = :note
                 WHERE id = :id"
            );
            $stmtUpd->execute(['id' => $id, 'note' => $newNote]);

            $stmtInc = $pdo->prepare(
                'UPDATE books
                 SET copies_available = LEAST(copies_total, copies_available + 1)
                 WHERE id = :id'
            );
            $stmtInc->execute(['id' => (int) $br['book_id']]);

            $pdo->commit();
            $flash = 'Lost borrowing closed — copy returned to inventory.';
        } elseif ($action === 'resolve_lost_paid') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $detail_note = isset($_POST['detail_note']) ? trim((string) $_POST['detail_note']) : '';
            if ($id <= 0) {
                throw new RuntimeException('Missing borrowing id.');
            }
            if ($detail_note !== '' && strlen($detail_note) > 500) {
                throw new RuntimeException('Note is too long (max 500 characters).');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT id, status, note
                 FROM borrowings
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $br = $stmt->fetch();
            if ($br === false) {
                throw new RuntimeException('Borrowing not found.');
            }
            if ((string) $br['status'] !== 'lost') {
                throw new RuntimeException('This record is not marked as lost.');
            }

            $line = 'Lost case closed: paid / settled (loan ended; shelf count unchanged — add a replacement copy in inventory if needed).';
            if ($detail_note !== '') {
                $line .= ' Note: ' . $detail_note;
            }
            $newNote = append_borrow_note((string) ($br['note'] ?? ''), $line);

            $stmtUpd = $pdo->prepare(
                "UPDATE borrowings
                 SET status = 'returned', returned_at = NOW(), note = :note
                 WHERE id = :id"
            );
            $stmtUpd->execute(['id' => $id, 'note' => $newNote]);

            $pdo->commit();
            $flash = 'Lost borrowing marked as paid / settled.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status_f = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
$role_f = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : '';
$overdue_f = isset($_GET['overdue']) ? trim((string) $_GET['overdue']) : '';
$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
$lost_history_only = false;

$date_from = borrow_parse_ymd(isset($_GET['date_from']) ? (string) $_GET['date_from'] : '');
$date_to = borrow_parse_ymd(isset($_GET['date_to']) ? (string) $_GET['date_to'] : '');
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    $tmp = $date_from;
    $date_from = $date_to;
    $date_to = $tmp;
}

if (!in_array($status_f, ['', 'borrowed', 'returned', 'lost'], true)) $status_f = '';
if (!in_array($role_f, ['', 'student', 'faculty', 'librarian'], true)) $role_f = '';
$overdue_f = ($overdue_f === '1') ? '1' : '';
if (!in_array($view, ['', 'all', 'borrowed', 'overdue', 'returned', 'lost', 'lost_resolved'], true)) $view = '';

// Tabs override (quick nav)
if ($view === '' || $view === 'all') {
    // keep explicit filters if user set them
} elseif ($view === 'borrowed') {
    $status_f = 'borrowed';
    $overdue_f = '';
} elseif ($view === 'overdue') {
    $status_f = '';
    $overdue_f = '1';
} elseif ($view === 'returned') {
    $status_f = 'returned';
    $overdue_f = '';
} elseif ($view === 'lost') {
    $status_f = 'lost';
    $overdue_f = '';
} elseif ($view === 'lost_resolved') {
    $lost_history_only = true;
    $status_f = '';
    $overdue_f = '';
}

$stmtUsers = $pdo->query(
    "SELECT id, full_name, role, department
     FROM users
     WHERE status = 'active'
     ORDER BY full_name ASC
     LIMIT 2000"
);
$users = $stmtUsers->fetchAll();

$stmtBooks = $pdo->query(
    "SELECT id, title, author, copies_available
     FROM books
     WHERE status = 'active'
     ORDER BY title ASC
     LIMIT 3000"
);
$books = $stmtBooks->fetchAll();

$whereParts = [];
$params = [];
if ($q !== '') {
    $whereParts[] = "(u.full_name LIKE :q
              OR u.department LIKE :q
              OR u.role LIKE :q
              OR b.title LIKE :q
              OR b.author LIKE :q
              OR br.status LIKE :q)";
    $params['q'] = '%' . $q . '%';
}
if ($status_f !== '') {
    $whereParts[] = 'br.status = :status';
    $params['status'] = $status_f;
}
if ($role_f !== '') {
    $whereParts[] = 'u.role = :role';
    $params['role'] = $role_f;
}
if ($overdue_f === '1') {
    $whereParts[] = "(br.status = 'borrowed' AND br.due_at IS NOT NULL AND br.due_at < NOW())";
}
if ($lost_history_only) {
    $whereParts[] = "br.status = 'returned'";
    $whereParts[] = '(br.lost_at IS NOT NULL OR br.note LIKE :lost_hist_a OR br.note LIKE :lost_hist_b)';
    $params['lost_hist_a'] = '%Lost case closed:%';
    $params['lost_hist_b'] = '%] Marked as lost.%';
}
if ($date_from !== '' && $date_to !== '') {
    $whereParts[] = 'DATE(br.borrowed_at) BETWEEN :bdate_from AND :bdate_to';
    $params['bdate_from'] = $date_from;
    $params['bdate_to'] = $date_to;
} elseif ($date_from !== '') {
    $whereParts[] = 'DATE(br.borrowed_at) >= :bdate_from';
    $params['bdate_from'] = $date_from;
} elseif ($date_to !== '') {
    $whereParts[] = 'DATE(br.borrowed_at) <= :bdate_to';
    $params['bdate_to'] = $date_to;
}

$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$orderSql = "(br.status = 'borrowed') DESC, br.borrowed_at DESC, br.id DESC";
if ($lost_history_only) {
    $orderSql = 'COALESCE(br.returned_at, br.borrowed_at) DESC, br.id DESC';
}

$stmt = $pdo->prepare(
    "SELECT br.*,
            u.full_name, u.role, u.department,
            b.title AS book_title, b.author AS book_author
     FROM borrowings br
     JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     {$where}
     ORDER BY {$orderSql}
     LIMIT 500"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && (string) $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="borrowings-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'id',
            'status',
            'patron',
            'role',
            'department',
            'book_title',
            'book_author',
            'borrowed_at',
            'due_at',
            'returned_at',
            'lost_at',
            'note',
        ]);
        foreach ($rows as $r) {
            fputcsv($out, [
                (int) $r['id'],
                (string) $r['status'],
                (string) $r['full_name'],
                (string) $r['role'],
                (string) $r['department'],
                (string) $r['book_title'],
                (string) ($r['book_author'] ?? ''),
                (string) $r['borrowed_at'],
                (string) ($r['due_at'] ?? ''),
                (string) ($r['returned_at'] ?? ''),
                (string) ($r['lost_at'] ?? ''),
                (string) ($r['note'] ?? ''),
            ]);
        }
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Borrowing — Admin</title>
    <link rel="stylesheet" href="<?= h(portal_asset('assets/admin.css')) ?>">
</head>
<body class="borrowings-page">
    <div class="admin-shell">
        <?php require __DIR__ . '/../includes/portal_sidebar.php'; ?>

        <main class="admin-main admin-page-list">
            <div class="container">
                <header class="admin-topbar">
                    <div>
                        <h1>Borrowings</h1>
                        <div class="subtitle">Issue, return, and track borrowings.</div>
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
                    <section class="card inventory-card directory-list-card" aria-label="Borrowings list">
                        <div class="card-body inventory-toolbar directory-list-toolbar">
                            <h2 class="card-title inventory-title">Borrowings</h2>

                            <form method="get" action="" class="inventory-actionbar inventory-actionbar--borrow" role="search" aria-label="Borrowings search and filters">
                                <button class="btn btn-primary inventory-add" type="button" data-open-issue>Issue Book</button>

                                <div class="inventory-search-wrap">
                                    <span class="inventory-search-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M21 21l-4.3-4.3"/>
                                            <circle cx="11" cy="11" r="7"/>
                                        </svg>
                                    </span>
                                    <input
                                        id="borrowingsSearch"
                                        class="inventory-search"
                                        name="q"
                                        placeholder="Search user, dept, book, or status"
                                        value="<?= h($q) ?>"
                                        autocomplete="off"
                                    >
                                </div>

                                <div class="borrow-inline-dates" aria-label="Borrow date range">
                                    <span class="muted borrow-inline-dates-label">Borrowed</span>
                                    <label class="borrow-date-label borrow-date-label--inline">
                                        <span>From</span>
                                        <input type="date" name="date_from" value="<?= h($date_from) ?>" class="borrow-date-input">
                                    </label>
                                    <label class="borrow-date-label borrow-date-label--inline">
                                        <span>To</span>
                                        <input type="date" name="date_to" value="<?= h($date_to) ?>" class="borrow-date-input">
                                    </label>
                                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                                    <?php if ($date_from !== '' || $date_to !== ''): ?>
                                        <a class="btn btn-sm btn-ghost" href="borrowings.php<?php
                                            $qd = http_build_query(borrowings_qs(['date_from' => '', 'date_to' => '']));
                                            echo $qd !== '' ? ('?' . h($qd)) : '';
                                        ?>">Clear dates</a>
                                    <?php endif; ?>
                                </div>

                                <?php if ($view !== ''): ?>
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                <?php endif; ?>
                                <?php if ($role_f !== ''): ?>
                                    <input type="hidden" name="role" value="<?= h($role_f) ?>">
                                <?php endif; ?>
                                <?php
                                    $clearQs = borrowings_qs(['q' => '']);
                                    $clearHref = 'borrowings.php' . ($clearQs !== [] ? ('?' . http_build_query($clearQs)) : '');
                                    $exportQs = http_build_query(borrowings_qs(['export' => 'csv']));
                                ?>
                                <div class="borrow-toolbar-end">
                                    <a
                                        id="borrowingsClear"
                                        class="btn btn-ghost inventory-clear<?= $q === '' ? ' is-hidden' : '' ?>"
                                        href="<?= h($clearHref) ?>"
                                    >Clear</a>
                                    <button type="button" class="btn btn-sm btn-ghost" data-print-borrowings>Print</button>
                                    <a class="btn btn-sm btn-ghost" href="borrowings.php<?= $exportQs !== '' ? ('?' . h($exportQs)) : '?export=csv' ?>">Export CSV</a>
                                </div>
                            </form>

                            <nav class="inventory-tabs borrow-toolbar-tabs" aria-label="Borrowings tabs">
                                <?php
                                    $mk = function (string $label, string $v): void {
                                        $qs = borrowings_qs(['view' => $v]);
                                        $gv = (string) ($GLOBALS['view'] ?? '');
                                        $is = ($v === 'all' && ($gv === '' || $gv === 'all')) || ($gv === $v);
                                        $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                        $hq = http_build_query($qs);
                                        echo '<a class="' . $cls . '" href="borrowings.php' . ($hq !== '' ? ('?' . h($hq)) : '') . '">' . h($label) . '</a>';
                                    };
                                    $mkRole = function (string $label, string $v): void {
                                        $qs = borrowings_qs(['role' => $v]);
                                        $rf = (string) ($GLOBALS['role_f'] ?? '');
                                        $is = ($v === '' && $rf === '') || ($rf === $v);
                                        $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                        $hq = http_build_query($qs);
                                        echo '<a class="' . $cls . '" href="borrowings.php' . ($hq !== '' ? ('?' . h($hq)) : '') . '">' . h($label) . '</a>';
                                    };

                                    $mk('All', 'all');
                                    $mk('Borrowed', 'borrowed');
                                    $mk('Overdue', 'overdue');
                                    $mk('Returned', 'returned');
                                    $mk('Lost', 'lost');
                                    $mk('Lost · settled', 'lost_resolved');

                                    echo '<span class="muted borrow-toolbar-divider" aria-hidden="true">Role</span>';
                                    $mkRole('All', '');
                                    $mkRole('Student', 'student');
                                    $mkRole('Faculty', 'faculty');
                                    $mkRole('Librarian', 'librarian');
                                ?>
                            </nav>
                        </div>

                        <div class="table-wrap directory-list-scroll">
                            <table class="directory-data-table directory-data-table--borrow">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Patron</th>
                                        <th>Book</th>
                                        <th>Borrowed</th>
                                        <th>Timeline</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$rows): ?>
                                    <tr><td colspan="5" class="muted">No borrowings found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                            $status = (string) $r['status'];
                                            $dueRaw = (string) ($r['due_at'] ?? '');
                                            $isOverdue = $status === 'borrowed'
                                                && $dueRaw !== ''
                                                && strtotime($dueRaw) < time();
                                            $pillClass = 'ok';
                                            if ($status === 'borrowed') {
                                                $pillClass = $isOverdue ? 'warn' : 'info';
                                            } elseif ($status === 'lost') {
                                                $pillClass = 'bad';
                                            }

                                            $lostAt = (string) ($r['lost_at'] ?? '');
                                            $timeline = '';
                                            if ($status === 'borrowed') {
                                                $timeline = $dueRaw !== '' ? ('Due ' . fmt_short_dt($dueRaw)) : 'No due date';
                                                if ($isOverdue) {
                                                    $timeline .= ' · overdue';
                                                }
                                            } elseif ($status === 'returned') {
                                                $noteStr = (string) ($r['note'] ?? '');
                                                $wasLostSettled = $lostAt !== ''
                                                    || str_contains($noteStr, 'Lost case closed:')
                                                    || str_contains($noteStr, '] Marked as lost.');
                                                if ($wasLostSettled) {
                                                    $timeline = 'Was lost · settled ' . fmt_short_dt((string) ($r['returned_at'] ?? ''));
                                                } else {
                                                    $timeline = 'Returned · ' . fmt_short_dt((string) ($r['returned_at'] ?? ''));
                                                }
                                            } else {
                                                $timeline = $lostAt !== '' ? ('Marked lost · ' . fmt_short_dt($lostAt)) : 'Lost';
                                            }

                                            $payload = [
                                                'id' => (int) $r['id'],
                                                'status' => $status,
                                                'full_name' => (string) $r['full_name'],
                                                'role' => (string) $r['role'],
                                                'department' => (string) $r['department'],
                                                'book_title' => (string) $r['book_title'],
                                                'book_author' => (string) ($r['book_author'] ?? ''),
                                                'borrowed_at' => (string) $r['borrowed_at'],
                                                'due_at' => (string) ($r['due_at'] ?? ''),
                                                'returned_at' => (string) ($r['returned_at'] ?? ''),
                                                'lost_at' => $lostAt,
                                                'note' => (string) ($r['note'] ?? ''),
                                            ];
                                            $payloadJson = h(json_encode($payload, JSON_UNESCAPED_UNICODE));
                                        ?>
                                        <tr
                                            class="borrow-row"
                                            tabindex="0"
                                            data-borrow="<?= $payloadJson ?>"
                                            aria-label="Open borrowing #<?= (int) $r['id'] ?>"
                                        >
                                            <td><span class="pill <?= h($pillClass) ?>"><?= h($status) ?></span></td>
                                            <td>
                                                <div class="borrow-patron-name"><?= h((string) $r['full_name']) ?></div>
                                                <div class="borrow-patron-meta muted"><?= h((string) $r['role']) ?> · <?= h((string) $r['department']) ?></div>
                                            </td>
                                            <td>
                                                <div class="borrow-book-title"><?= h((string) $r['book_title']) ?></div>
                                                <?php if (!empty($r['book_author'])): ?>
                                                    <div class="borrow-book-author muted"><?= h((string) $r['book_author']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="borrow-date-cell"><?= h(fmt_short_dt((string) $r['borrowed_at'])) ?></td>
                                            <td class="borrow-timeline-cell muted"><?= h($timeline) ?></td>
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

    <!-- Borrowing detail + actions -->
    <div class="modal modal-layered" id="borrowDetailModal" aria-hidden="true">
        <div class="modal-panel modal-panel-borrow" role="dialog" aria-modal="true" aria-labelledby="borrowDetailTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="borrowDetailTitle">Borrowing</h2>
                <button class="icon-btn" type="button" data-close-borrow-detail aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body borrow-detail-body">
                <div class="borrow-detail-hero">
                    <div class="borrow-detail-id muted" id="borrowDetailIdLine"></div>
                    <div class="borrow-detail-book" id="borrowDetailBook"></div>
                    <div class="borrow-detail-author muted" id="borrowDetailAuthor" hidden></div>
                </div>
                <dl class="borrow-detail-dl" id="borrowDetailMeta"></dl>
                <div class="borrow-detail-extra" id="borrowDetailExtraWrap" hidden>
                    <label for="borrowDetailExtraNote">Optional note (appended to log)</label>
                    <textarea id="borrowDetailExtraNote" class="borrow-detail-textarea" rows="2" maxlength="500" placeholder="e.g. receipt no., condition, follow-up…"></textarea>
                </div>
                <div class="borrow-detail-section">
                    <div class="borrow-detail-section-title">History &amp; log</div>
                    <ul class="borrow-detail-timeline" id="borrowDetailTimeline"></ul>
                    <pre class="borrow-detail-notes" id="borrowDetailNotes" hidden></pre>
                </div>
                <div class="borrow-action-grid" id="borrowDetailActions" hidden></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-borrow-detail>Close</button>
            </div>
        </div>
    </div>

    <!-- Issue a book modal -->
    <div class="modal" id="issueModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="issueTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="issueTitle">Issue a book</h2>
                <button class="icon-btn" type="button" data-close-issue aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <form method="post" action="" id="issueForm">
                    <input type="hidden" name="action" value="issue">

                    <label for="issue_user_id">User</label>
                    <select id="issue_user_id" name="user_id" required>
                        <option value="">Select user…</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= h((string)$u['full_name']) ?> — <?= h((string)$u['role']) ?> — <?= h((string)$u['department']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="issue_book_id">Book</label>
                    <select id="issue_book_id" name="book_id" required>
                        <option value="">Select book…</option>
                        <?php foreach ($books as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                <?= h((string)$b['title']) ?>
                                <?php if (!empty($b['author'])): ?> — <?= h((string)$b['author']) ?><?php endif; ?>
                                — avail: <?= (int)$b['copies_available'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="issue_due_at">Due at</label>
                    <input id="issue_due_at" name="due_at" type="datetime-local" value="<?= h(dt_local_default_due()) ?>">

                    <label for="issue_note">Note (optional)</label>
                    <input id="issue_note" name="note" value="">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-issue>Cancel</button>
                <button class="btn btn-primary" type="submit" form="issueForm">Issue</button>
            </div>
        </div>
    </div>

    <script src="../assets/app_ajax.js"></script>
    <script>
        (function () {
            const search = document.getElementById('borrowingsSearch');
            const clearBtn = document.getElementById('borrowingsClear');
            function syncClearVisibility() {
                if (!search || !clearBtn) return;
                const hasText = String(search.value || '').trim().length > 0;
                clearBtn.classList.toggle('is-hidden', !hasText);
                clearBtn.setAttribute('aria-hidden', hasText ? 'false' : 'true');
            }
            if (search) {
                search.addEventListener('input', syncClearVisibility);
                syncClearVisibility();
            }

            const issueModal = document.getElementById('issueModal');
            function openIssueModal() {
                if (!issueModal) return;
                issueModal.classList.add('is-open');
                issueModal.setAttribute('aria-hidden', 'false');
            }
            function closeIssueModal() {
                if (!issueModal) return;
                issueModal.classList.remove('is-open');
                issueModal.setAttribute('aria-hidden', 'true');
            }

            const openBtn = document.querySelector('[data-open-issue]');
            if (openBtn) openBtn.addEventListener('click', openIssueModal);
            document.querySelectorAll('[data-close-issue]').forEach(function (b) {
                b.addEventListener('click', closeIssueModal);
            });
            if (issueModal) {
                issueModal.addEventListener('click', function (e) {
                    if (e.target === issueModal) closeIssueModal();
                });
            }

            function showAjaxFlash(text, isErr) {
                var el = document.getElementById('ajaxFlash');
                if (!el || !text) return;
                el.textContent = text;
                el.className = 'msg ' + (isErr ? 'err' : 'ok');
                el.style.display = '';
                el.setAttribute('role', isErr ? 'alert' : 'status');
            }

            function postBorrowAction(action, id, detailNote) {
                var fd = new FormData();
                fd.set('__ajax', '1');
                fd.set('action', action);
                fd.set('id', String(id));
                if (detailNote && String(detailNote).trim()) {
                    fd.set('detail_note', String(detailNote).trim());
                }
                return fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' },
                }).then(function (res) { return res.json(); });
            }

            const detailModal = document.getElementById('borrowDetailModal');
            const detailTitle = document.getElementById('borrowDetailTitle');
            const detailIdLine = document.getElementById('borrowDetailIdLine');
            const detailBook = document.getElementById('borrowDetailBook');
            const detailAuthor = document.getElementById('borrowDetailAuthor');
            const detailMeta = document.getElementById('borrowDetailMeta');
            const detailTimeline = document.getElementById('borrowDetailTimeline');
            const detailNotes = document.getElementById('borrowDetailNotes');
            const detailActions = document.getElementById('borrowDetailActions');
            const detailExtraWrap = document.getElementById('borrowDetailExtraWrap');
            const detailExtraNote = document.getElementById('borrowDetailExtraNote');

            var currentBorrowId = 0;

            function fmtModal(iso) {
                if (!iso || !String(iso).trim()) return '';
                var s = String(iso).trim().replace(' ', 'T');
                var dt = new Date(s);
                if (isNaN(dt.getTime())) return String(iso);
                return dt.toLocaleString(undefined, {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                });
            }

            function closeBorrowDetail() {
                if (!detailModal) return;
                detailModal.classList.remove('is-open');
                detailModal.setAttribute('aria-hidden', 'true');
                if (detailExtraNote) detailExtraNote.value = '';
                currentBorrowId = 0;
                selectBorrowRow(null, false);
            }

            function openBorrowDetail() {
                if (!detailModal) return;
                detailModal.classList.add('is-open');
                detailModal.setAttribute('aria-hidden', 'false');
            }

            document.querySelectorAll('[data-close-borrow-detail]').forEach(function (b) {
                b.addEventListener('click', closeBorrowDetail);
            });
            if (detailModal) {
                detailModal.addEventListener('click', function (e) {
                    if (e.target === detailModal) closeBorrowDetail();
                });
            }

            function dlRow(metaEl, label, value) {
                var dt = document.createElement('dt');
                dt.textContent = label;
                var dd = document.createElement('dd');
                dd.textContent = value && String(value).trim() ? String(value) : '—';
                metaEl.appendChild(dt);
                metaEl.appendChild(dd);
            }

            function timelineLi(ul, title, value) {
                if (!value || !String(value).trim()) return;
                var li = document.createElement('li');
                var s = document.createElement('span');
                s.className = 'borrow-detail-timeline-label';
                s.textContent = title;
                li.appendChild(s);
                li.appendChild(document.createTextNode(' ' + String(value)));
                ul.appendChild(li);
            }

            function makeActionTile(label, sub, clsMod, act) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'borrow-action-tile' + (clsMod ? (' ' + clsMod) : '');
                var t = document.createElement('span');
                t.className = 'borrow-action-tile-title';
                t.textContent = label;
                btn.appendChild(t);
                if (sub) {
                    var p = document.createElement('span');
                    p.className = 'borrow-action-tile-sub muted';
                    p.textContent = sub;
                    btn.appendChild(p);
                }
                btn.setAttribute('data-tile-action', act);
                return btn;
            }

            function populateBorrowDetail(d) {
                if (detailTitle) detailTitle.textContent = 'Borrowing #' + d.id;
                if (detailIdLine) {
                    detailIdLine.textContent = d.status === 'borrowed' ? 'Active loan' : (d.status === 'lost' ? 'Lost item' : 'Closed record');
                }
                if (detailBook) detailBook.textContent = d.book_title || '—';
                if (detailAuthor) {
                    var au = d.book_author && String(d.book_author).trim();
                    detailAuthor.textContent = au ? au : '';
                    detailAuthor.hidden = !au;
                }
                if (detailMeta) {
                    detailMeta.innerHTML = '';
                    dlRow(detailMeta, 'Patron', d.full_name);
                    dlRow(detailMeta, 'Role', d.role);
                    dlRow(detailMeta, 'Department', d.department);
                }
                if (detailTimeline) {
                    detailTimeline.innerHTML = '';
                    timelineLi(detailTimeline, 'Borrowed', fmtModal(d.borrowed_at));
                    timelineLi(detailTimeline, 'Due', fmtModal(d.due_at));
                    timelineLi(detailTimeline, 'Marked lost', fmtModal(d.lost_at));
                    if (d.status === 'returned' && d.returned_at) {
                        timelineLi(detailTimeline, d.lost_at ? 'Settled / closed' : 'Returned', fmtModal(d.returned_at));
                    }
                }
                if (detailNotes) {
                    var n = d.note && String(d.note).trim();
                    if (n) {
                        detailNotes.textContent = n;
                        detailNotes.hidden = false;
                    } else {
                        detailNotes.textContent = '';
                        detailNotes.hidden = true;
                    }
                }
                if (detailExtraWrap) {
                    var showExtra = d.status === 'borrowed' || d.status === 'lost';
                    detailExtraWrap.hidden = !showExtra;
                }
                if (detailActions) {
                    detailActions.innerHTML = '';
                    detailActions.hidden = false;
                    if (d.status === 'borrowed') {
                        detailActions.appendChild(makeActionTile(
                            'Return book',
                            'Copy goes back to available shelf stock.',
                            'borrow-action-tile--success',
                            'return'
                        ));
                        detailActions.appendChild(makeActionTile(
                            'Mark as lost',
                            'Patron did not return; loan ends as lost.',
                            'borrow-action-tile--danger',
                            'mark_lost'
                        ));
                    } else if (d.status === 'lost') {
                        detailActions.appendChild(makeActionTile(
                            'Book came back',
                            'Physical copy returned — inventory +1.',
                            'borrow-action-tile--success',
                            'resolve_lost_returned'
                        ));
                        detailActions.appendChild(makeActionTile(
                            'Paid / settled',
                            'Fee received; loan closed without adding a copy.',
                            'borrow-action-tile--warn',
                            'resolve_lost_paid'
                        ));
                    } else {
                        detailActions.hidden = true;
                    }
                }
            }

            function runBorrowTileAction(act, id) {
                var extra = detailExtraNote ? detailExtraNote.value : '';
                if (act === 'mark_lost' && !window.confirm('Mark this loan as LOST?')) return;
                if (act === 'resolve_lost_paid' && !window.confirm('Close as paid / settled without returning a physical copy?')) return;
                postBorrowAction(act, id, extra).then(function (data) {
                    if (data && data.ok) window.location.reload();
                    else showAjaxFlash((data && (data.message || data.error)) || 'Error', true);
                }).catch(function () { showAjaxFlash('Network error.', true); });
            }

            if (detailActions) {
                detailActions.addEventListener('click', function (e) {
                    var t = e.target && e.target.closest('[data-tile-action]');
                    if (!t || !detailModal || !detailModal.classList.contains('is-open')) return;
                    var act = t.getAttribute('data-tile-action');
                    var id = currentBorrowId;
                    if (!act || !id) return;
                    runBorrowTileAction(act, id);
                });
            }

            function selectBorrowRow(tr, on) {
                document.querySelectorAll('tr.borrow-row.is-selected').forEach(function (x) {
                    x.classList.remove('is-selected');
                });
                if (on && tr) tr.classList.add('is-selected');
            }

            function activateBorrowRow(tr) {
                var raw = tr.getAttribute('data-borrow');
                if (!raw) return;
                var d;
                try { d = JSON.parse(raw); } catch (e) { return; }
                currentBorrowId = d.id || 0;
                selectBorrowRow(tr, true);
                populateBorrowDetail(d);
                openBorrowDetail();
            }

            document.querySelectorAll('tr.borrow-row').forEach(function (tr) {
                tr.addEventListener('click', function () {
                    activateBorrowRow(tr);
                });
                tr.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        activateBorrowRow(tr);
                    }
                });
            });

            var issueForm = document.getElementById('issueForm');
            if (issueForm && window.ajaxPostForm) {
                issueForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    ajaxPostForm(issueForm).then(function (data) {
                        if (data.ok) window.location.reload();
                        else showAjaxFlash(data.message || data.error || 'Error', true);
                    }).catch(function () { showAjaxFlash('Network error.', true); });
                });
            }

            document.querySelectorAll('[data-print-borrowings]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    window.print();
                });
            });

        })();
    </script>
</body>
</html>

