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

function book_cover_src(?string $cover_path, string $fallback_title): string
{
    if ($cover_path !== null && $cover_path !== '') {
        $p = trim((string) $cover_path);
        if (preg_match('#^https?://#i', $p) === 1 || str_starts_with($p, '//')) {
            return $p;
        }
        return app_public_path($p);
    }
    $name = trim($fallback_title) !== '' ? trim($fallback_title) : 'Book';
    return ui_avatar_url($name);
}

/** @param array<string, mixed> $row */
function issue_user_label_id(array $row): string
{
    $username = trim((string) ($row['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }
    return '#' . (int) ($row['id'] ?? 0);
}

function dt_local_default_due(): string
{
    $d = new DateTimeImmutable('now');
    $d = $d->modify('+7 days')->setTime(17, 0);
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
    "SELECT id, full_name, role, department, rfid_tag, username, avatar_path
     FROM users
     WHERE status = 'active'
     ORDER BY full_name ASC
     LIMIT 2000"
);
$users = $stmtUsers->fetchAll();

$stmtBooks = $pdo->query(
    "SELECT id, title, author, isbn, edition, cover_path, copies_available, copies_total
     FROM books
     WHERE status = 'active'
     ORDER BY title ASC
     LIMIT 3000"
);
$books = $stmtBooks->fetchAll();

$issue_users_json = [];
foreach ($users as $u) {
    $name = (string) $u['full_name'];
    $issue_users_json[] = [
        'id' => (int) $u['id'],
        'full_name' => $name,
        'role' => (string) $u['role'],
        'department' => (string) $u['department'],
        'rfid_tag' => (string) $u['rfid_tag'],
        'user_label' => issue_user_label_id($u),
        'avatar' => public_avatar_src(
            isset($u['avatar_path']) ? (string) $u['avatar_path'] : null,
            $name
        ),
        'search' => strtolower(implode(' ', array_filter([
            $name,
            (string) $u['role'],
            (string) $u['department'],
            (string) $u['rfid_tag'],
            (string) ($u['username'] ?? ''),
            (string) $u['id'],
        ]))),
    ];
}

$issue_books_json = [];
foreach ($books as $b) {
    $title = (string) $b['title'];
    $avail = (int) $b['copies_available'];
    $total = (int) $b['copies_total'];
    $issue_books_json[] = [
        'id' => (int) $b['id'],
        'title' => $title,
        'author' => (string) ($b['author'] ?? ''),
        'isbn' => (string) ($b['isbn'] ?? ''),
        'edition' => (string) ($b['edition'] ?? ''),
        'copies_available' => $avail,
        'copies_total' => $total,
        'available' => $avail > 0,
        'cover' => book_cover_src(
            isset($b['cover_path']) ? (string) $b['cover_path'] : null,
            $title
        ),
        'search' => strtolower(implode(' ', array_filter([
            $title,
            (string) ($b['author'] ?? ''),
            (string) ($b['isbn'] ?? ''),
            (string) ($b['edition'] ?? ''),
        ]))),
    ];
}

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
        <div class="modal-panel modal-panel-issue" role="dialog" aria-modal="true" aria-labelledby="issueTitle">
            <div class="issue-modal-header">
                <div class="issue-modal-header__brand">
                    <span class="issue-modal-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    </span>
                    <div>
                        <h2 class="issue-modal-title" id="issueTitle">Issue a Book</h2>
                        <p class="issue-modal-sub">Fill in the details below to issue a book to a user.</p>
                    </div>
                </div>
                <button class="icon-btn" type="button" data-close-issue aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body issue-modal-body">
                <form method="post" action="" id="issueForm" novalidate>
                    <input type="hidden" name="action" value="issue">
                    <input type="hidden" name="user_id" id="issue_user_id" value="">
                    <input type="hidden" name="book_id" id="issue_book_id" value="">

                    <div class="issue-steps-grid">
                        <section class="issue-step" aria-labelledby="issueStepUserLabel">
                            <h3 class="issue-step-label" id="issueStepUserLabel">
                                <span class="issue-step-num">1.</span> Select User
                            </h3>
                            <div class="issue-step-panel" id="issueUserStepPanel">
                                <div class="issue-user-toolbar" id="issueUserToolbar">
                                    <div class="issue-search-wrap issue-search-wrap--grow">
                                        <span class="issue-search-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3"/><circle cx="11" cy="11" r="7"/></svg>
                                        </span>
                                        <input type="search" id="issueUserSearch" class="issue-search" placeholder="Search by name, RFID, ID or username…" autocomplete="off" aria-controls="issueUserResults" aria-expanded="false" aria-autocomplete="list">
                                        <div class="issue-dropdown" id="issueUserResults" role="listbox" hidden></div>
                                    </div>
                                    <button type="button" class="btn btn-ghost issue-rfid-scan-btn" id="issueUserRfidScan">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h2"/><path d="M18 12h2"/><path d="M12 4v2"/><path d="M12 18v2"/><circle cx="12" cy="12" r="4"/></svg>
                                        Scan RFID
                                    </button>
                                </div>
                                <div class="issue-picker" id="issueUserPicker">
                                    <div class="issue-selected" id="issueUserSelected" hidden>
                                    <img class="issue-selected__thumb" id="issueUserSelectedAvatar" alt="" width="44" height="44">
                                    <div class="issue-selected__body">
                                        <div class="issue-selected__top">
                                            <span class="issue-selected__title" id="issueUserSelectedName"></span>
                                            <span class="issue-pill issue-pill--role" id="issueUserSelectedRole"></span>
                                        </div>
                                        <p class="issue-selected__meta" id="issueUserSelectedMeta"></p>
                                        <p class="issue-selected__sub" id="issueUserSelectedDept"></p>
                                    </div>
                                    <span class="issue-selected__ok" aria-hidden="true">
                                        <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                    </span>
                                    <button type="button" class="issue-selected__clear" id="issueUserClear" aria-label="Clear user">
                                        <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                                    </button>
                                    </div>
                                    <p class="issue-picker-empty" id="issueUserEmpty">Search or scan RFID to select a user</p>
                                </div>
                            </div>
                        </section>

                        <section class="issue-step" aria-labelledby="issueStepBookLabel">
                            <h3 class="issue-step-label" id="issueStepBookLabel">
                                <span class="issue-step-num">2.</span> Select Book
                            </h3>
                            <div class="issue-step-panel" id="issueBookStepPanel">
                                <div class="issue-book-toolbar" id="issueBookToolbar">
                                    <div class="issue-search-wrap issue-search-wrap--grow">
                                        <span class="issue-search-ico" aria-hidden="true">
                                            <svg viewBox="0 0 24 24"><path d="M21 21l-4.3-4.3"/><circle cx="11" cy="11" r="7"/></svg>
                                        </span>
                                        <input type="search" id="issueBookSearch" class="issue-search" placeholder="Search by title, author, ISBN or keyword…" autocomplete="off" aria-controls="issueBookResults" aria-expanded="false" aria-autocomplete="list">
                                        <div class="issue-dropdown" id="issueBookResults" role="listbox" hidden></div>
                                    </div>
                                </div>
                                <div class="issue-picker" id="issueBookPicker">
                                <div class="issue-selected issue-selected--book" id="issueBookSelected" hidden>
                                    <img class="issue-selected__thumb issue-selected__thumb--book" id="issueBookSelectedCover" alt="" width="44" height="58">
                                    <div class="issue-selected__body">
                                        <div class="issue-selected__top">
                                            <span class="issue-selected__title" id="issueBookSelectedTitle"></span>
                                            <span class="issue-pill issue-pill--ok" id="issueBookSelectedAvail">Available</span>
                                        </div>
                                        <p class="issue-selected__meta" id="issueBookSelectedAuthor"></p>
                                        <p class="issue-selected__sub" id="issueBookSelectedMeta"></p>
                                        <p class="issue-selected__avail" id="issueBookSelectedCopies"></p>
                                    </div>
                                    <span class="issue-selected__ok" aria-hidden="true">
                                        <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                                    </span>
                                    <button type="button" class="issue-selected__clear" id="issueBookClear" aria-label="Clear book">
                                        <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                    <p class="issue-picker-empty" id="issueBookEmpty">Search to select a book</p>
                                </div>
                            </div>
                        </section>

                        <section class="issue-step" aria-labelledby="issueStepDueLabel">
                            <h3 class="issue-step-label" id="issueStepDueLabel">
                                <span class="issue-step-num">3.</span> Due Date
                            </h3>
                            <div class="issue-step-panel issue-step-panel--due">
                            <div class="issue-due-wrap">
                                <label class="issue-due-field" for="issue_due_at">
                                    <span class="issue-due-field__ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                    </span>
                                    <input id="issue_due_at" name="due_at" type="datetime-local" value="<?= h(dt_local_default_due()) ?>" required>
                                </label>
                                <div class="issue-due-presets" role="group" aria-label="Quick due date">
                                    <button type="button" class="issue-due-preset" data-issue-days="3">+ 3 days</button>
                                    <button type="button" class="issue-due-preset is-active" data-issue-days="7">+ 7 days (Default)</button>
                                    <button type="button" class="issue-due-preset" data-issue-days="14">+ 14 days</button>
                                    <button type="button" class="issue-due-preset" data-issue-days="30">Faculty 30 days</button>
                                </div>
                                <p class="issue-due-hint">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                                    Default due date for students is 7 days.
                                </p>
                            </div>
                            </div>
                        </section>

                        <section class="issue-step" aria-labelledby="issueStepNoteLabel">
                            <h3 class="issue-step-label" id="issueStepNoteLabel">
                                <span class="issue-step-num">4.</span> Note (Optional)
                            </h3>
                            <div class="issue-step-panel issue-step-panel--note">
                            <div class="issue-note-wrap">
                                <textarea id="issue_note" name="note" maxlength="200" placeholder="Add a note (e.g. For research, assignment, thesis…)"></textarea>
                                <span class="issue-note-count" id="issueNoteCount">0 / 200 characters</span>
                            </div>
                            </div>
                        </section>
                    </div>

                    <section class="issue-summary" aria-label="Issue summary">
                        <h3 class="issue-summary-title">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                            Issue Summary
                        </h3>
                        <div class="issue-summary-grid">
                            <div class="issue-summary-col">
                                <span class="issue-summary-label">User</span>
                                <div class="issue-summary-card" id="issueSummaryUser">
                                    <span class="issue-summary-empty">No user selected</span>
                                </div>
                            </div>
                            <div class="issue-summary-col">
                                <span class="issue-summary-label">Book</span>
                                <div class="issue-summary-card" id="issueSummaryBook">
                                    <span class="issue-summary-empty">No book selected</span>
                                </div>
                            </div>
                            <div class="issue-summary-col">
                                <span class="issue-summary-label">Due Date</span>
                                <div class="issue-summary-card issue-summary-card--due" id="issueSummaryDue">
                                    <span class="issue-summary-empty">—</span>
                                </div>
                            </div>
                            <div class="issue-summary-col">
                                <span class="issue-summary-label">Available Copies</span>
                                <div class="issue-summary-card issue-summary-card--copies" id="issueSummaryCopies">
                                    <span class="issue-summary-empty">—</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </form>
            </div>
            <div class="modal-footer issue-modal-footer">
                <p class="issue-footer-hint">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Please confirm all details before issuing the book.
                </p>
                <div class="issue-footer-actions">
                    <button class="btn btn-ghost" type="button" data-close-issue>Cancel</button>
                    <button class="btn btn-primary issue-submit-btn" type="submit" form="issueForm" id="issueSubmitBtn" disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                        Issue Book
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- RFID capture for issue user -->
    <div class="modal modal-layered" id="issueRfidScanModal" aria-hidden="true">
        <div class="modal-panel modal-panel-rfid-scan" role="dialog" aria-modal="true" aria-labelledby="issueRfidScanTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="issueRfidScanTitle">Scan user RFID</h2>
                <button class="icon-btn" type="button" data-close-issue-rfid-scan aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p class="hint user-rfid-scan-intro">Focus stays in the field below. Scan the card now, or type the tag ID and press Enter.</p>
                <label for="issueRfidScanInput">RFID input</label>
                <input id="issueRfidScanInput" type="text" autocomplete="off" spellcheck="false" inputmode="text" class="user-rfid-scan-field" placeholder="Waiting for scan…">
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-issue-rfid-scan>Cancel</button>
                <button class="btn btn-primary" type="button" id="issueRfidScanApply">Select user</button>
            </div>
        </div>
    </div>

    <script type="application/json" id="issueUsersData"><?= json_encode($issue_users_json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script type="application/json" id="issueBooksData"><?= json_encode($issue_books_json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

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
            const issueUsers = (function () {
                var el = document.getElementById('issueUsersData');
                if (!el) return [];
                try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
            })();
            const issueBooks = (function () {
                var el = document.getElementById('issueBooksData');
                if (!el) return [];
                try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
            })();

            var issueState = { user: null, book: null, presetDays: 7 };

            function issueEsc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function issueFmtRole(role) {
                if (!role) return '';
                return role.charAt(0).toUpperCase() + role.slice(1);
            }

            function issueDueAt17h(days) {
                var d = new Date();
                d.setDate(d.getDate() + days);
                d.setHours(17, 0, 0, 0);
                var y = d.getFullYear();
                var m = String(d.getMonth() + 1).padStart(2, '0');
                var day = String(d.getDate()).padStart(2, '0');
                var h = String(d.getHours()).padStart(2, '0');
                var min = String(d.getMinutes()).padStart(2, '0');
                return y + '-' + m + '-' + day + 'T' + h + ':' + min;
            }

            function issueFmtSummaryDue(isoLocal) {
                if (!isoLocal) return { date: '—', time: '' };
                var dt = new Date(isoLocal);
                if (isNaN(dt.getTime())) return { date: '—', time: '' };
                return {
                    date: dt.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }),
                    time: dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }),
                };
            }

            function issueSyncSubmit() {
                var btn = document.getElementById('issueSubmitBtn');
                var uid = document.getElementById('issue_user_id');
                var bid = document.getElementById('issue_book_id');
                var ok = issueState.user && issueState.book && issueState.book.available;
                if (btn) btn.disabled = !ok;
                if (uid) uid.value = issueState.user ? String(issueState.user.id) : '';
                if (bid) bid.value = issueState.book ? String(issueState.book.id) : '';
            }

            function issueRenderSummary() {
                var su = document.getElementById('issueSummaryUser');
                var sb = document.getElementById('issueSummaryBook');
                var sd = document.getElementById('issueSummaryDue');
                var sc = document.getElementById('issueSummaryCopies');
                var dueInput = document.getElementById('issue_due_at');

                if (su) {
                    if (!issueState.user) {
                        su.innerHTML = '<span class="issue-summary-empty">No user selected</span>';
                    } else {
                        var u = issueState.user;
                        su.innerHTML =
                            '<img class="issue-summary-thumb" src="' + issueEsc(u.avatar) + '" alt="" width="36" height="36">' +
                            '<div class="issue-summary-body">' +
                            '<span class="issue-summary-name">' + issueEsc(u.full_name) + '</span>' +
                            '<span class="issue-summary-meta">RFID: ' + issueEsc(u.rfid_tag) + ' · ID: ' + issueEsc(u.user_label) + '</span>' +
                            '<span class="issue-pill issue-pill--role">' + issueEsc(issueFmtRole(u.role)) + '</span>' +
                            '</div>';
                    }
                }

                if (sb) {
                    if (!issueState.book) {
                        sb.innerHTML = '<span class="issue-summary-empty">No book selected</span>';
                    } else {
                        var b = issueState.book;
                        var author = b.author ? ('by ' + b.author) : '';
                        sb.innerHTML =
                            '<img class="issue-summary-thumb issue-summary-thumb--book" src="' + issueEsc(b.cover) + '" alt="" width="32" height="42">' +
                            '<div class="issue-summary-body">' +
                            '<span class="issue-summary-name">' + issueEsc(b.title) + '</span>' +
                            (author ? '<span class="issue-summary-meta">' + issueEsc(author) + '</span>' : '') +
                            '</div>';
                    }
                }

                if (sd && dueInput) {
                    var parts = issueFmtSummaryDue(dueInput.value);
                    if (!dueInput.value) {
                        sd.innerHTML = '<span class="issue-summary-empty">—</span>';
                    } else {
                        sd.innerHTML =
                            '<span class="issue-summary-due-ico" aria-hidden="true">' +
                            '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>' +
                            '<div class="issue-summary-body">' +
                            '<span class="issue-summary-name">' + issueEsc(parts.date) + '</span>' +
                            (parts.time ? '<span class="issue-summary-meta">' + issueEsc(parts.time) + '</span>' : '') +
                            '</div>';
                    }
                }

                if (sc) {
                    if (!issueState.book) {
                        sc.innerHTML = '<span class="issue-summary-empty">—</span>';
                    } else {
                        var bk = issueState.book;
                        var remain = Math.max(0, bk.copies_available - 1);
                        sc.innerHTML =
                            '<span class="issue-summary-copies-ico" aria-hidden="true">' +
                            '<svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>' +
                            '<div class="issue-summary-body">' +
                            '<span class="issue-summary-name">' + remain + ' of ' + bk.copies_total + '</span>' +
                            '<span class="issue-summary-meta">copies will remain</span>' +
                            '</div>';
                    }
                }
            }

            function issueSyncUserPanel() {
                var has = !!issueState.user;
                var panel = document.getElementById('issueUserStepPanel');
                var toolbar = document.getElementById('issueUserToolbar');
                var empty = document.getElementById('issueUserEmpty');
                var picker = document.getElementById('issueUserPicker');
                if (panel) panel.classList.toggle('has-selection', has);
                if (toolbar) toolbar.hidden = has;
                if (empty) empty.hidden = has;
                if (picker) picker.classList.toggle('has-selection', has);
            }

            function issueSyncBookPanel() {
                var has = !!issueState.book;
                var panel = document.getElementById('issueBookStepPanel');
                var toolbar = document.getElementById('issueBookToolbar');
                var empty = document.getElementById('issueBookEmpty');
                var picker = document.getElementById('issueBookPicker');
                if (panel) panel.classList.toggle('has-selection', has);
                if (toolbar) toolbar.hidden = has;
                if (empty) empty.hidden = has;
                if (picker) picker.classList.toggle('has-selection', has);
            }

            function issueNormalizeRfid(raw) {
                return String(raw || '').replace(/[\r\n\x00]/g, '').trim();
            }

            function issueFindUserByRfid(tag) {
                var t = issueNormalizeRfid(tag).toLowerCase();
                if (!t) return null;
                for (var i = 0; i < issueUsers.length; i++) {
                    if (String(issueUsers[i].rfid_tag || '').toLowerCase() === t) {
                        return issueUsers[i];
                    }
                }
                return null;
            }

            function issueSelectUser(user) {
                issueState.user = user;
                var search = document.getElementById('issueUserSearch');
                var selected = document.getElementById('issueUserSelected');
                issueHideResults('user');
                if (search) search.value = '';
                issueSyncUserPanel();
                if (selected && user) {
                    selected.hidden = false;
                    var av = document.getElementById('issueUserSelectedAvatar');
                    if (av) { av.src = user.avatar; av.alt = user.full_name; }
                    var nm = document.getElementById('issueUserSelectedName');
                    if (nm) nm.textContent = user.full_name;
                    var rl = document.getElementById('issueUserSelectedRole');
                    if (rl) rl.textContent = issueFmtRole(user.role);
                    var meta = document.getElementById('issueUserSelectedMeta');
                    if (meta) meta.textContent = 'RFID: ' + user.rfid_tag + ' · ID: ' + user.user_label;
                    var dept = document.getElementById('issueUserSelectedDept');
                    if (dept) dept.textContent = user.department;
                }
                issueSyncSubmit();
                issueRenderSummary();
            }

            function issueClearUser() {
                issueState.user = null;
                var search = document.getElementById('issueUserSearch');
                var selected = document.getElementById('issueUserSelected');
                if (selected) selected.hidden = true;
                issueSyncUserPanel();
                if (search) search.value = '';
                issueSyncSubmit();
                issueRenderSummary();
            }

            function issueSelectBook(book) {
                if (!book || !book.available) return;
                issueState.book = book;
                var search = document.getElementById('issueBookSearch');
                var selected = document.getElementById('issueBookSelected');
                issueHideResults('book');
                if (search) search.value = '';
                issueSyncBookPanel();
                if (selected && book) {
                    selected.hidden = false;
                    var cov = document.getElementById('issueBookSelectedCover');
                    if (cov) { cov.src = book.cover; cov.alt = book.title; }
                    var ti = document.getElementById('issueBookSelectedTitle');
                    if (ti) ti.textContent = book.title;
                    var au = document.getElementById('issueBookSelectedAuthor');
                    if (au) au.textContent = book.author ? ('by ' + book.author) : '';
                    var meta = document.getElementById('issueBookSelectedMeta');
                    if (meta) {
                        var bits = [];
                        if (book.isbn) bits.push('ISBN: ' + book.isbn);
                        if (book.edition) bits.push('Edition: ' + book.edition);
                        meta.textContent = bits.join(' · ');
                    }
                    var copies = document.getElementById('issueBookSelectedCopies');
                    if (copies) copies.textContent = book.copies_available + ' of ' + book.copies_total + ' copies available';
                    var avail = document.getElementById('issueBookSelectedAvail');
                    if (avail) {
                        avail.textContent = book.available ? 'Available' : 'Unavailable';
                        avail.classList.toggle('issue-pill--warn', !book.available);
                        avail.classList.toggle('issue-pill--ok', book.available);
                    }
                }
                issueSyncSubmit();
                issueRenderSummary();
            }

            function issueClearBook() {
                issueState.book = null;
                var search = document.getElementById('issueBookSearch');
                var selected = document.getElementById('issueBookSelected');
                if (selected) selected.hidden = true;
                issueSyncBookPanel();
                if (search) search.value = '';
                issueSyncSubmit();
                issueRenderSummary();
            }

            function issueFilterList(items, q, max) {
                q = String(q || '').trim().toLowerCase();
                var out = items;
                if (q) {
                    out = items.filter(function (it) {
                        return String(it.search || '').indexOf(q) !== -1;
                    });
                }
                return out.slice(0, max || 12);
            }

            function issueSetDropdownOpen(kind, open) {
                var picker = document.getElementById(kind === 'user' ? 'issueUserPicker' : 'issueBookPicker');
                var panel = document.getElementById(kind === 'user' ? 'issueUserStepPanel' : 'issueBookStepPanel');
                if (picker) picker.classList.toggle('is-open', !!open);
                if (panel) panel.classList.toggle('is-dropdown-open', !!open);
            }

            function issueRenderUserResults(q) {
                var box = document.getElementById('issueUserResults');
                var search = document.getElementById('issueUserSearch');
                if (!box || issueState.user) return;
                q = String(q || '').trim();
                if (!q) {
                    issueHideResults('user');
                    return;
                }
                issueHideResults('book');
                var list = issueFilterList(issueUsers, q, 10);
                box.innerHTML = '';
                if (!list.length) {
                    box.innerHTML = '<p class="issue-dropdown-empty">No users found</p>';
                } else {
                    list.forEach(function (u) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'issue-result-item';
                        btn.setAttribute('role', 'option');
                        btn.innerHTML =
                            '<img class="issue-result-item__thumb" src="' + issueEsc(u.avatar) + '" alt="" width="36" height="36">' +
                            '<span class="issue-result-item__body">' +
                            '<span class="issue-result-item__title">' + issueEsc(u.full_name) + '</span>' +
                            '<span class="issue-result-item__sub">' + issueEsc(issueFmtRole(u.role)) + ' · ' + issueEsc(u.department) + '</span>' +
                            '<span class="issue-result-item__meta">RFID: ' + issueEsc(u.rfid_tag) + '</span>' +
                            '</span>';
                        btn.addEventListener('click', function () { issueSelectUser(u); });
                        box.appendChild(btn);
                    });
                }
                box.hidden = false;
                issueSetDropdownOpen('user', true);
                if (search) search.setAttribute('aria-expanded', 'true');
            }

            function issueRenderBookResults(q) {
                var box = document.getElementById('issueBookResults');
                var search = document.getElementById('issueBookSearch');
                if (!box || issueState.book) return;
                q = String(q || '').trim();
                if (!q) {
                    issueHideResults('book');
                    return;
                }
                issueHideResults('user');
                var list = issueFilterList(issueBooks, q, 10);
                box.innerHTML = '';
                if (!list.length) {
                    box.innerHTML = '<p class="issue-dropdown-empty">No books found</p>';
                } else {
                    list.forEach(function (b) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'issue-result-item' + (b.available ? '' : ' is-disabled');
                        btn.setAttribute('role', 'option');
                        btn.disabled = !b.available;
                        var author = b.author ? ('by ' + b.author) : '';
                        btn.innerHTML =
                            '<img class="issue-result-item__thumb issue-result-item__thumb--book" src="' + issueEsc(b.cover) + '" alt="" width="32" height="42">' +
                            '<span class="issue-result-item__body">' +
                            '<span class="issue-result-item__title">' + issueEsc(b.title) + '</span>' +
                            (author ? '<span class="issue-result-item__sub">' + issueEsc(author) + '</span>' : '') +
                            '<span class="issue-result-item__meta">' + (b.available ? (b.copies_available + ' available') : 'No copies available') + '</span>' +
                            '</span>';
                        btn.addEventListener('click', function () { issueSelectBook(b); });
                        box.appendChild(btn);
                    });
                }
                box.hidden = false;
                issueSetDropdownOpen('book', true);
                if (search) search.setAttribute('aria-expanded', 'true');
            }

            function issueHideResults(kind) {
                var box = document.getElementById(kind === 'user' ? 'issueUserResults' : 'issueBookResults');
                var search = document.getElementById(kind === 'user' ? 'issueUserSearch' : 'issueBookSearch');
                if (box) { box.hidden = true; box.innerHTML = ''; }
                if (search) search.setAttribute('aria-expanded', 'false');
                issueSetDropdownOpen(kind, false);
            }

            function issueResetForm() {
                issueState = { user: null, book: null, presetDays: 7 };
                issueClearUser();
                issueClearBook();
                issueSyncUserPanel();
                issueSyncBookPanel();
                var note = document.getElementById('issue_note');
                if (note) note.value = '';
                var nc = document.getElementById('issueNoteCount');
                if (nc) nc.textContent = '0 / 200 characters';
                var due = document.getElementById('issue_due_at');
                if (due) due.value = issueDueAt17h(7);
                document.querySelectorAll('.issue-due-preset').forEach(function (btn) {
                    btn.classList.toggle('is-active', btn.getAttribute('data-issue-days') === '7');
                });
                issueSyncSubmit();
                issueRenderSummary();
            }

            function openIssueModal() {
                if (!issueModal) return;
                issueResetForm();
                issueModal.classList.add('is-open');
                issueModal.setAttribute('aria-hidden', 'false');
                var us = document.getElementById('issueUserSearch');
                if (us) setTimeout(function () { us.focus(); }, 80);
            }
            function closeIssueModal() {
                if (!issueModal) return;
                issueModal.classList.remove('is-open');
                issueModal.setAttribute('aria-hidden', 'true');
                issueHideResults('user');
                issueHideResults('book');
            }

            ['issueUserResults', 'issueBookResults'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                    });
                }
            });

            var issueUserSearch = document.getElementById('issueUserSearch');
            if (issueUserSearch) {
                issueUserSearch.addEventListener('input', function () {
                    issueRenderUserResults(issueUserSearch.value);
                });
                issueUserSearch.addEventListener('focus', function () {
                    if (!issueState.user && String(issueUserSearch.value || '').trim()) {
                        issueRenderUserResults(issueUserSearch.value);
                    }
                });
            }
            var issueBookSearch = document.getElementById('issueBookSearch');
            if (issueBookSearch) {
                issueBookSearch.addEventListener('input', function () {
                    issueRenderBookResults(issueBookSearch.value);
                });
                issueBookSearch.addEventListener('focus', function () {
                    if (!issueState.book && String(issueBookSearch.value || '').trim()) {
                        issueRenderBookResults(issueBookSearch.value);
                    }
                });
            }
            document.getElementById('issueUserClear')?.addEventListener('click', issueClearUser);
            document.getElementById('issueBookClear')?.addEventListener('click', issueClearBook);

            var issueRfidScanModal = document.getElementById('issueRfidScanModal');
            var issueRfidScanInput = document.getElementById('issueRfidScanInput');
            var issueRfidScanApply = document.getElementById('issueRfidScanApply');
            var issueUserRfidScan = document.getElementById('issueUserRfidScan');

            function openIssueRfidScanModal() {
                if (!issueRfidScanModal || !issueRfidScanInput) return;
                issueRfidScanInput.value = '';
                issueRfidScanModal.classList.add('is-open');
                issueRfidScanModal.setAttribute('aria-hidden', 'false');
                window.setTimeout(function () {
                    issueRfidScanInput.focus();
                    issueRfidScanInput.select();
                }, 60);
            }

            function closeIssueRfidScanModal() {
                if (!issueRfidScanModal) return;
                issueRfidScanModal.classList.remove('is-open');
                issueRfidScanModal.setAttribute('aria-hidden', 'true');
            }

            function applyIssueRfidScan() {
                if (!issueRfidScanInput) return;
                var user = issueFindUserByRfid(issueRfidScanInput.value);
                if (!user) {
                    showAjaxFlash('No active user found for this RFID tag.', true);
                    issueRfidScanInput.focus();
                    return;
                }
                closeIssueRfidScanModal();
                issueSelectUser(user);
            }

            if (issueUserRfidScan) {
                issueUserRfidScan.addEventListener('click', openIssueRfidScanModal);
            }
            if (issueRfidScanApply) {
                issueRfidScanApply.addEventListener('click', applyIssueRfidScan);
            }
            document.querySelectorAll('[data-close-issue-rfid-scan]').forEach(function (b) {
                b.addEventListener('click', closeIssueRfidScanModal);
            });
            if (issueRfidScanModal) {
                issueRfidScanModal.addEventListener('click', function (e) {
                    if (e.target === issueRfidScanModal) closeIssueRfidScanModal();
                });
            }
            if (issueRfidScanInput) {
                issueRfidScanInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyIssueRfidScan();
                    }
                });
            }

            document.querySelectorAll('.issue-due-preset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var days = parseInt(btn.getAttribute('data-issue-days') || '7', 10);
                    issueState.presetDays = days;
                    document.querySelectorAll('.issue-due-preset').forEach(function (b) {
                        b.classList.toggle('is-active', b === btn);
                    });
                    var due = document.getElementById('issue_due_at');
                    if (due) due.value = issueDueAt17h(days);
                    issueRenderSummary();
                });
            });
            var issueDueInput = document.getElementById('issue_due_at');
            if (issueDueInput) {
                issueDueInput.addEventListener('change', issueRenderSummary);
                issueDueInput.addEventListener('input', issueRenderSummary);
            }
            var issueNote = document.getElementById('issue_note');
            if (issueNote) {
                issueNote.addEventListener('input', function () {
                    var nc = document.getElementById('issueNoteCount');
                    if (nc) nc.textContent = issueNote.value.length + ' / 200 characters';
                });
            }

            document.addEventListener('click', function (e) {
                if (!issueModal || !issueModal.classList.contains('is-open')) return;
                var t = e.target;
                if (issueUserSearch && !document.getElementById('issueUserStepPanel')?.contains(t)) {
                    issueHideResults('user');
                }
                if (issueBookSearch && !document.getElementById('issueBookStepPanel')?.contains(t)) {
                    issueHideResults('book');
                }
            });

            issueSyncUserPanel();
            issueSyncBookPanel();

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
                if (window.showActionMessage) {
                    window.showActionMessage(text, isErr);
                    return;
                }
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
                    if (data && data.ok) {
                        if (window.ajaxReloadOnSuccess) window.ajaxReloadOnSuccess(data);
                        else window.location.reload();
                    } else {
                        showAjaxFlash((data && (data.message || data.error)) || 'Error', true);
                    }
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
                    if (!issueState.user || !issueState.book) {
                        showAjaxFlash('Select a user and a book.', true);
                        return;
                    }
                    if (!issueState.book.available) {
                        showAjaxFlash('No copies available for this book.', true);
                        return;
                    }
                    issueSyncSubmit();
                    ajaxPostForm(issueForm).then(function (data) {
                        if (data.ok) {
                            if (window.ajaxReloadOnSuccess) window.ajaxReloadOnSuccess(data);
                            else window.location.reload();
                        } else {
                            showAjaxFlash(data.message || data.error || 'Error', true);
                        }
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

