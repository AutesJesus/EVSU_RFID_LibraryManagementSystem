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

function admin_avatar_src(PDO $pdo, int $admin_id, string $fallback_username): string
{
    $stmt = $pdo->prepare('SELECT username, avatar_path FROM admins WHERE id = :id');
    $stmt->execute(['id' => $admin_id]);
    $row = $stmt->fetch() ?: [];
    $username = (string)($row['username'] ?? $fallback_username);
    $path = (string)($row['avatar_path'] ?? '');
    if ($path !== '') {
        $p = trim($path);
        if (preg_match('#^https?://#i', $p) === 1 || str_starts_with($p, '//')) {
            return $p;
        }
        return app_public_path($p);
    }
    return ui_avatar_url($username);
}

function clamp_int(int $v, int $min, int $max): int
{
    return max($min, min($max, $v));
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

/** Local SVG when cover URL fails to load. */
function book_cover_img_fallback_data_uri(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="112" viewBox="0 0 80 112">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1c2333"/><stop offset="1" stop-color="#121722"/></linearGradient></defs>'
        . '<rect width="80" height="112" rx="8" fill="url(#g)"/>'
        . '<path fill="rgba(230,237,243,.22)" d="M22 24h36v4H22zm0 12h28v3H22zm0 10h36v3H22zm0 10h22v3H22z"/></svg>';
    $cached = 'data:image/svg+xml;base64,' . base64_encode($svg);
    return $cached;
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'add' || $action === 'edit') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $title = isset($_POST['title']) ? trim((string) $_POST['title']) : '';
            $author = isset($_POST['author']) ? trim((string) $_POST['author']) : '';
            $isbn = isset($_POST['isbn']) ? trim((string) $_POST['isbn']) : '';
            $description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';
            $genre = isset($_POST['genre']) ? trim((string) $_POST['genre']) : '';
            $language = isset($_POST['language']) ? trim((string) $_POST['language']) : '';
            $edition = isset($_POST['edition']) ? trim((string) $_POST['edition']) : '';
            $copies_total = isset($_POST['copies_total']) ? (int) $_POST['copies_total'] : 1;
            $status = isset($_POST['status']) ? (string) $_POST['status'] : 'active';

            if ($title === '') {
                throw new RuntimeException('Title is required.');
            }
            if (!in_array($status, ['active', 'archived'], true)) {
                $status = 'active';
            }

            $copies_total = clamp_int($copies_total, 1, 9999);

            $save_uploaded_cover = static function (): ?string {
                if (!isset($_FILES['cover']) || !is_array($_FILES['cover'])) {
                    return null;
                }
                $f = $_FILES['cover'];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    return null;
                }
                if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Cover image upload failed.');
                }
                $tmp = (string) ($f['tmp_name'] ?? '');
                $name = (string) ($f['name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Invalid cover upload.');
                }
                $mime = (string) @mime_content_type($tmp);
                $ext = sanitize_upload_ext($mime, $name);
                if ($ext === '') {
                    throw new RuntimeException('Cover must be JPG, PNG, or WEBP.');
                }
                if ((int) ($f['size'] ?? 0) > 3 * 1024 * 1024) {
                    throw new RuntimeException('Cover must be 3MB or smaller.');
                }
                $relDir = 'uploads';
                $absDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relDir;
                if (!is_dir($absDir)) {
                    @mkdir($absDir, 0775, true);
                }
                $filename = 'book_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
                if (!move_uploaded_file($tmp, $absPath)) {
                    throw new RuntimeException('Failed to save cover image.');
                }
                return $relDir . '/' . $filename;
            };

            if ($action === 'add') {
                $cover_path = $save_uploaded_cover();
                $stmt = $pdo->prepare(
                    'INSERT INTO books (title, author, isbn, description, cover_path, genre, language, edition, copies_total, copies_available, status)
                     VALUES (:title, :author, :isbn, :description, :cover_path, :genre, :language, :edition, :copies_total, :copies_available, :status)'
                );
                $stmt->execute([
                    'title' => $title,
                    'author' => $author !== '' ? $author : null,
                    'isbn' => $isbn !== '' ? $isbn : null,
                    'description' => $description !== '' ? $description : null,
                    'cover_path' => $cover_path,
                    'genre' => $genre !== '' ? $genre : null,
                    'language' => $language !== '' ? $language : null,
                    'edition' => $edition !== '' ? $edition : null,
                    'copies_total' => $copies_total,
                    'copies_available' => $copies_total,
                    'status' => $status,
                ]);
                $flash = 'Book added to inventory.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('Missing book id.');
                }

                $stmtExisting = $pdo->prepare('SELECT id, copies_total, copies_available FROM books WHERE id = :id');
                $stmtExisting->execute(['id' => $id]);
                $existing = $stmtExisting->fetch();
                if ($existing === false) {
                    throw new RuntimeException('Book not found.');
                }

                $old_total = (int) $existing['copies_total'];
                $old_available = (int) $existing['copies_available'];
                $borrowed_now = max(0, $old_total - $old_available);

                if ($copies_total < $borrowed_now) {
                    throw new RuntimeException('Copies total cannot be less than copies currently borrowed (' . $borrowed_now . ').');
                }

                $new_available = $copies_total - $borrowed_now;

                $cover_sql = '';
                $params = [
                    'id' => $id,
                    'title' => $title,
                    'author' => $author !== '' ? $author : null,
                    'isbn' => $isbn !== '' ? $isbn : null,
                    'description' => $description !== '' ? $description : null,
                    'genre' => $genre !== '' ? $genre : null,
                    'language' => $language !== '' ? $language : null,
                    'edition' => $edition !== '' ? $edition : null,
                    'copies_total' => $copies_total,
                    'copies_available' => $new_available,
                    'status' => $status,
                ];
                $newCover = $save_uploaded_cover();
                if ($newCover !== null) {
                    $cover_sql = ', cover_path = :cover_path';
                    $params['cover_path'] = $newCover;
                }

                $stmt = $pdo->prepare(
                    "UPDATE books
                     SET title = :title,
                         author = :author,
                         isbn = :isbn,
                         description = :description,
                         genre = :genre,
                         language = :language,
                         edition = :edition,
                         copies_total = :copies_total,
                         copies_available = :copies_available,
                         status = :status
                         {$cover_sql}
                     WHERE id = :id"
                );
                $stmt->execute($params);
                $flash = 'Book updated.';
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing book id.');
            }

            $stmtOpen = $pdo->prepare("SELECT COUNT(*) AS c FROM borrowings WHERE book_id = :id AND status = 'borrowed'");
            $stmtOpen->execute(['id' => $id]);
            $open = (int) $stmtOpen->fetch()['c'];
            if ($open > 0) {
                throw new RuntimeException('Cannot delete: book currently has active borrowings.');
            }

            $stmt = $pdo->prepare('DELETE FROM books WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $flash = 'Book deleted.';
        } elseif ($action === 'adjust_available') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $copies_available = isset($_POST['copies_available']) ? (int) $_POST['copies_available'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing book id.');
            }

            $stmtExisting = $pdo->prepare('SELECT id, copies_total, copies_available FROM books WHERE id = :id');
            $stmtExisting->execute(['id' => $id]);
            $existing = $stmtExisting->fetch();
            if ($existing === false) {
                throw new RuntimeException('Book not found.');
            }

            $total = (int) $existing['copies_total'];
            $copies_available = clamp_int($copies_available, 0, $total);

            $stmt = $pdo->prepare('UPDATE books SET copies_available = :a WHERE id = :id');
            $stmt->execute(['id' => $id, 'a' => $copies_available]);
            $flash = 'Availability updated.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false) {
            $msg = 'Duplicate value detected.';
        }
        $error = $msg;
    }

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status_f = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
$avail_f = isset($_GET['avail']) ? strtolower(trim((string) $_GET['avail'])) : '';
$view = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$edit_book = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    $edit_book = $stmt->fetch() ?: null;
}

$genre_options = [];
try {
    $go = $pdo->query(
        "SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND TRIM(genre) <> '' ORDER BY genre ASC LIMIT 48"
    );
    foreach ($go->fetchAll(PDO::FETCH_COLUMN) as $g) {
        $g = trim((string) $g);
        if ($g !== '') {
            $genre_options[] = $g;
        }
    }
    $genre_options = array_values(array_unique($genre_options, SORT_STRING));
} catch (Throwable $e) {
    $genre_options = [];
}

$language_options = [];
try {
    $lo = $pdo->query(
        "SELECT DISTINCT language FROM books WHERE language IS NOT NULL AND TRIM(language) <> '' ORDER BY language ASC LIMIT 32"
    );
    foreach ($lo->fetchAll(PDO::FETCH_COLUMN) as $ln) {
        $ln = trim((string) $ln);
        if ($ln !== '') {
            $language_options[] = $ln;
        }
    }
    $language_options = array_values(array_unique($language_options, SORT_STRING));
} catch (Throwable $e) {
    $language_options = [];
}

$book_genre_datalist = array_values(array_unique(array_merge(
    $genre_options,
    ['Fiction', 'Non-fiction', 'Science & Technology', 'History', 'Biography', 'Reference', 'Education']
), SORT_STRING));
$book_language_datalist = array_values(array_unique(array_merge(
    $language_options,
    ['English', 'Filipino', 'Spanish', 'French', 'Other']
), SORT_STRING));

$genre_f = isset($_GET['genre']) ? trim((string) $_GET['genre']) : '';
if ($genre_f !== '' && !in_array($genre_f, $genre_options, true)) {
    $genre_f = '';
}
$language_f = isset($_GET['language']) ? trim((string) $_GET['language']) : '';
if ($language_f !== '' && !in_array($language_f, $language_options, true)) {
    $language_f = '';
}

if (!in_array($status_f, ['', 'active', 'archived'], true)) {
    $status_f = '';
}
if (!in_array($avail_f, ['', 'in', 'out'], true)) {
    $avail_f = '';
}
if (!in_array($view, ['', 'all', 'active', 'borrowed', 'overdue', 'returned', 'out', 'history'], true)) {
    $view = '';
}

// Tabs override (quick nav)
if ($view === '' || $view === 'all') {
    // keep explicit selects if user set them
} elseif ($view === 'active') {
    $status_f = 'active';
} elseif ($view === 'out') {
    $avail_f = 'out';
} elseif ($view === 'borrowed') {
    // handled in SQL HAVING borrowed_count > 0
} elseif ($view === 'overdue') {
    // handled in SQL HAVING overdue_count > 0
} elseif ($view === 'returned') {
    // handled in SQL HAVING returned_count > 0
} elseif ($view === 'history') {
    // handled in SQL HAVING history_count > 0
}

$whereParts = [];
$params = [];
if ($q !== '') {
    $whereParts[] = '(b.title LIKE :q OR b.author LIKE :q OR b.isbn LIKE :q OR b.description LIKE :q OR b.genre LIKE :q OR b.language LIKE :q OR b.edition LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($status_f !== '') {
    $whereParts[] = 'b.status = :status';
    $params['status'] = $status_f;
}
if ($genre_f !== '') {
    $whereParts[] = 'b.genre = :genre_f';
    $params['genre_f'] = $genre_f;
}
if ($language_f !== '') {
    $whereParts[] = 'b.language = :language_f';
    $params['language_f'] = $language_f;
}
if ($avail_f === 'in') {
    $whereParts[] = 'b.copies_available > 0';
} elseif ($avail_f === 'out') {
    $whereParts[] = 'b.copies_available <= 0';
}

$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$stmt = $pdo->prepare(
    "SELECT b.*,
            COALESCE(SUM(CASE WHEN br.status = 'borrowed' THEN 1 ELSE 0 END), 0) AS borrowed_count,
            COALESCE(SUM(CASE WHEN br.status = 'borrowed' AND br.due_at IS NOT NULL AND br.due_at < NOW() THEN 1 ELSE 0 END), 0) AS overdue_count,
            COALESCE(SUM(CASE WHEN br.status = 'returned' THEN 1 ELSE 0 END), 0) AS returned_count,
            COALESCE(COUNT(br.id), 0) AS history_count
     FROM books b
     LEFT JOIN borrowings br ON br.book_id = b.id
     {$where}
     GROUP BY b.id
     " . (
        $view === 'borrowed' ? 'HAVING borrowed_count > 0' :
        ($view === 'overdue' ? 'HAVING overdue_count > 0' :
        ($view === 'returned' ? 'HAVING returned_count > 0' :
        ($view === 'history' ? 'HAVING history_count > 0' : '')))
     ) . "
     ORDER BY b.created_at DESC, b.id DESC
     LIMIT 500"
);
$stmt->execute($params);
$books = $stmt->fetchAll();

if (!isset($admin_avatar_src) || $admin_avatar_src === '') {
    $admin_avatar_src = admin_avatar_src($pdo, (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_username'] ?? 'Admin'));
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory — Admin</title>
    <link rel="stylesheet" href="<?= h(portal_asset('assets/admin.css')) ?>">
</head>
<body>
    <div class="admin-shell">
        <?php require __DIR__ . '/../includes/portal_sidebar.php'; ?>

        <main class="admin-main admin-page-list">
            <div class="container">
                <header class="admin-topbar">
                    <div>
                        <h1>Inventory</h1>
                        <div class="subtitle">Click a row to open a book - edit details, cover, or delete .</div>
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
                    <section class="card users-directory inventory-card books-inventory directory-list-card" aria-label="Books list">
                        <div class="card-body inventory-toolbar directory-list-toolbar">
                            <h2 class="card-title inventory-title">Books</h2>

                            <div class="inventory-toolbar-top-row">
                            <form method="get" action="" class="inventory-actionbar inventory-actionbar--grow" role="search" aria-label="Inventory search">
                                <button class="btn btn-primary inventory-add" type="button" data-open-book-modal="add">Add Book</button>

                                <div class="inventory-search-wrap">
                                    <span class="inventory-search-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M21 21l-4.3-4.3"/>
                                            <circle cx="11" cy="11" r="7"/>
                                        </svg>
                                    </span>
                                    <input
                                        id="inventorySearch"
                                        class="inventory-search"
                                        name="q"
                                        placeholder="Search title, author, ISBN, description, genre…"
                                        value="<?= h($q) ?>"
                                        autocomplete="off"
                                    >
                                </div>

                                <button class="btn btn-primary" type="submit">Apply</button>
                                <?php if ($view !== '' && $view !== 'all'): ?>
                                    <input type="hidden" name="view" value="<?= h($view) ?>">
                                <?php endif; ?>
                                <?php if ($status_f !== ''): ?>
                                    <input type="hidden" name="status" value="<?= h($status_f) ?>">
                                <?php endif; ?>
                                <?php if ($avail_f !== ''): ?>
                                    <input type="hidden" name="avail" value="<?= h($avail_f) ?>">
                                <?php endif; ?>
                                <?php
                                    $clearQs = [];
                                    if ($view !== '' && $view !== 'all') {
                                        $clearQs['view'] = $view;
                                    }
                                    if ($status_f !== '') {
                                        $clearQs['status'] = $status_f;
                                    }
                                    if ($avail_f !== '') {
                                        $clearQs['avail'] = $avail_f;
                                    }
                                    if ($genre_f !== '') {
                                        $clearQs['genre'] = $genre_f;
                                    }
                                    if ($language_f !== '') {
                                        $clearQs['language'] = $language_f;
                                    }
                                    $clearHref = 'inventory.php' . ($clearQs ? ('?' . http_build_query($clearQs)) : '');
                                ?>
                                <a
                                    id="inventoryClear"
                                    class="btn btn-ghost inventory-clear<?= $q === '' ? ' is-hidden' : '' ?>"
                                    href="<?= h($clearHref) ?>"
                                >Clear</a>
                            </form>

                            <div class="inventory-toolbar-filters-end" aria-label="Genre and language filters">
                                <?php
                                    $baseCatQs = static function () use ($q, $view, $avail_f, $status_f, $genre_f, $language_f): array {
                                        $qs = [];
                                        if ($q !== '') {
                                            $qs['q'] = $q;
                                        }
                                        if ($view !== '' && $view !== 'all') {
                                            $qs['view'] = $view;
                                        }
                                        if ($avail_f !== '') {
                                            $qs['avail'] = $avail_f;
                                        }
                                        if ($status_f !== '') {
                                            $qs['status'] = $status_f;
                                        }
                                        if ($genre_f !== '') {
                                            $qs['genre'] = $genre_f;
                                        }
                                        if ($language_f !== '') {
                                            $qs['language'] = $language_f;
                                        }
                                        return $qs;
                                    };
                                    $genreFilterHref = static function (?string $v) use ($baseCatQs): string {
                                        $qs = $baseCatQs();
                                        unset($qs['genre']);
                                        if ($v !== null && $v !== '') {
                                            $qs['genre'] = $v;
                                        }
                                        return 'inventory.php?' . http_build_query($qs);
                                    };
                                    $languageFilterHref = static function (?string $v) use ($baseCatQs): string {
                                        $qs = $baseCatQs();
                                        unset($qs['language']);
                                        if ($v !== null && $v !== '') {
                                            $qs['language'] = $v;
                                        }
                                        return 'inventory.php?' . http_build_query($qs);
                                    };
                                ?>
                                <div class="inventory-select-field">
                                    <label for="invFilterGenre">Genre</label>
                                    <select
                                        id="invFilterGenre"
                                        class="inventory-filter-select"
                                        onchange="window.location.href=this.value;"
                                    >
                                        <option value="<?= h($genreFilterHref(null)) ?>"<?= $genre_f === '' ? ' selected' : '' ?>>All genres</option>
                                        <?php foreach ($genre_options as $gopt): ?>
                                            <option
                                                value="<?= h($genreFilterHref($gopt)) ?>"
                                                <?= $genre_f === $gopt ? ' selected' : '' ?>
                                            ><?= h($gopt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="inventory-select-field">
                                    <label for="invFilterLanguage">Language</label>
                                    <select
                                        id="invFilterLanguage"
                                        class="inventory-filter-select"
                                        onchange="window.location.href=this.value;"
                                    >
                                        <option value="<?= h($languageFilterHref(null)) ?>"<?= $language_f === '' ? ' selected' : '' ?>>All languages</option>
                                        <?php foreach ($language_options as $lopt): ?>
                                            <option
                                                value="<?= h($languageFilterHref($lopt)) ?>"
                                                <?= $language_f === $lopt ? ' selected' : '' ?>
                                            ><?= h($lopt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            </div>

                            <nav class="inventory-tabs" aria-label="Inventory views">
                            <?php
                                $mkView = function (string $label, string $v) use ($q, $genre_f, $language_f, $status_f, $avail_f): void {
                                    $qs = [];
                                    if ($q !== '') {
                                        $qs['q'] = $q;
                                    }
                                    if ($status_f !== '') {
                                        $qs['status'] = $status_f;
                                    }
                                    if ($avail_f !== '') {
                                        $qs['avail'] = $avail_f;
                                    }
                                    if ($genre_f !== '') {
                                        $qs['genre'] = $genre_f;
                                    }
                                    if ($language_f !== '') {
                                        $qs['language'] = $language_f;
                                    }
                                    $qs['view'] = $v;
                                    $is = ($v === 'all' && ($GLOBALS['view'] === '' || $GLOBALS['view'] === 'all')) || ($GLOBALS['view'] === $v);
                                    $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                    echo '<a class="' . $cls . '" href="inventory.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                };
                                $mkView('All', 'all');
                                $mkView('Active', 'active');
                                $mkView('Borrowed', 'borrowed');
                                $mkView('Overdue', 'overdue');
                                $mkView('Returned', 'returned');
                                $mkView('Out of Stock', 'out');
                                $mkView('History', 'history');
                            ?>
                            </nav>
                        </div>

                        <div class="table-wrap directory-list-scroll">
                            <table class="users-directory-table directory-data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th class="users-col-photo book-col-cover">Cover</th>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>ISBN</th>
                                        <th>Genre</th>
                                        <th>Lang.</th>
                                        <th>Available</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Borrowed</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$books): ?>
                                    <tr><td colspan="12" class="muted">No books yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($books as $b): ?>
                                        <?php
                                            $row_cover = book_cover_src((string)($b['cover_path'] ?? ''), (string)$b['title']);
                                        ?>
                                        <tr
                                            class="js-row-open-book users-table__row"
                                            tabindex="0"
                                            role="button"
                                            title="Open book"
                                            data-book='<?= h(json_encode([
                                                'id' => (int)$b['id'],
                                                'title' => (string)$b['title'],
                                                'author' => (string)($b['author'] ?? ''),
                                                'isbn' => (string)($b['isbn'] ?? ''),
                                                'description' => (string)($b['description'] ?? ''),
                                                'genre' => (string)($b['genre'] ?? ''),
                                                'language' => (string)($b['language'] ?? ''),
                                                'edition' => (string)($b['edition'] ?? ''),
                                                'copies_available' => (int)$b['copies_available'],
                                                'copies_total' => (int)$b['copies_total'],
                                                'status' => (string)$b['status'],
                                                'created_at' => (string)$b['created_at'],
                                                'borrowed_count' => (int)($b['borrowed_count'] ?? 0),
                                                'cover_src' => $row_cover,
                                            ], JSON_UNESCAPED_SLASHES)) ?>'
                                        >
                                            <td><?= (int)$b['id'] ?></td>
                                            <td class="users-col-photo book-col-cover">
                                                <img
                                                    class="book-table-cover"
                                                    src="<?= h($row_cover) ?>"
                                                    alt=""
                                                    width="40"
                                                    height="56"
                                                    loading="lazy"
                                                    decoding="async"
                                                    referrerpolicy="no-referrer"
                                                    onerror="this.onerror=null;this.src='<?= h(book_cover_img_fallback_data_uri()) ?>'"
                                                >
                                            </td>
                                            <td><?= h((string)$b['title']) ?></td>
                                            <td><?= h((string)($b['author'] ?? '')) ?></td>
                                            <td><?= h((string)($b['isbn'] ?? '')) ?></td>
                                            <td><?= h((string)($b['genre'] ?? '')) !== '' ? h((string)$b['genre']) : '<span class="users-cell-empty">—</span>' ?></td>
                                            <td><?= h((string)($b['language'] ?? '')) !== '' ? h((string)$b['language']) : '<span class="users-cell-empty">—</span>' ?></td>
                                            <td>
                                                <?php if ((int)$b['copies_available'] > 0): ?>
                                                    <span class="pill ok"><?= (int)$b['copies_available'] ?></span>
                                                <?php else: ?>
                                                    <span class="pill bad">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= (int)$b['copies_total'] ?></td>
                                            <td>
                                                <?php if (($b['status'] ?? '') === 'active'): ?>
                                                    <span class="pill ok">active</span>
                                                <?php else: ?>
                                                    <span class="pill bad">archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)($b['borrowed_count'] ?? 0) > 0): ?>
                                                    <span class="pill warn"><?= (int)$b['borrowed_count'] ?></span>
                                                <?php else: ?>
                                                    <span class="muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string)$b['created_at']) ?></td>
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

    <!-- Book Add/Edit modal -->
    <div class="modal" id="bookModal" aria-hidden="true">
        <div class="modal-panel modal-panel-book" role="dialog" aria-modal="true" aria-labelledby="bookModalTitle">
            <header class="book-modal-header">
                <div class="book-modal-header__brand">
                    <div class="book-modal-header__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        </svg>
                    </div>
                    <div class="book-modal-header__text">
                        <h2 class="book-modal-header__title" id="bookModalTitle">Add New Book</h2>
                        <p class="book-modal-header__sub" id="bookModalSubtitle">Fill in the details below to add a new book to the library.</p>
                    </div>
                </div>
                <button class="icon-btn book-modal-close" type="button" data-close-book-modal aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </header>

            <div class="modal-body modal-body-book">
                <form method="post" action="" enctype="multipart/form-data" id="bookForm" class="book-form" novalidate>
                    <input type="hidden" name="action" value="add" id="bookFormAction">
                    <input type="hidden" name="id" value="" id="bookId">

                    <div class="book-modal-grid">
                        <section class="book-modal-section book-modal-section--cover" aria-labelledby="bookSectionCoverTitle">
                            <h3 class="book-modal-section__title" id="bookSectionCoverTitle">
                                <span class="book-modal-section__num">1.</span>
                                <span class="book-modal-section__icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                </span>
                                Book Cover
                            </h3>
                            <label class="book-cover-drop" id="bookCoverDropzone" for="bookCover">
                                <input id="bookCover" name="cover" type="file" accept="image/png,image/jpeg,image/webp" class="book-cover-drop__input" tabindex="-1">
                                <div class="book-cover-drop__preview" id="bookCoverPreviewWrap" hidden>
                                    <img
                                        src="<?= h(book_cover_src(null, 'Book')) ?>"
                                        alt=""
                                        class="book-cover-drop__img"
                                        id="bookCoverPreview"
                                        width="140"
                                        height="196"
                                        loading="lazy"
                                        decoding="async"
                                        referrerpolicy="no-referrer"
                                        onerror="this.onerror=null;this.src=<?= json_encode(book_cover_img_fallback_data_uri(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>"
                                    >
                                </div>
                                <div class="book-cover-drop__empty" id="bookCoverDropEmpty">
                                    <span class="book-cover-drop__upload-icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <polyline points="17 8 12 3 7 8"/>
                                            <line x1="12" y1="3" x2="12" y2="15"/>
                                        </svg>
                                    </span>
                                    <span class="book-cover-drop__label">Upload Book Cover</span>
                                    <span class="book-cover-drop__hint">Drag and drop an image here, or click to browse</span>
                                    <span class="book-cover-drop__meta">JPG, PNG or WEBP · Max 3MB</span>
                                </div>
                            </label>
                            <p class="book-cover-drop__edit-hint muted" id="bookCoverEditHint" hidden>Leave empty to keep the current cover when saving.</p>
                        </section>

                        <div class="book-modal-main">
                            <section class="book-modal-section" aria-labelledby="bookSectionDetailsTitle">
                                <h3 class="book-modal-section__title" id="bookSectionDetailsTitle">
                                    <span class="book-modal-section__num">2.</span>
                                    <span class="book-modal-section__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </span>
                                    Book Details
                                </h3>

                                <div class="book-field">
                                    <label class="book-field__label" for="bookTitle">Title <span class="req" aria-hidden="true">*</span></label>
                                    <input id="bookTitle" name="title" required value="" placeholder="Enter book title" autocomplete="off">
                                </div>

                                <div class="book-field">
                                    <label class="book-field__label" for="bookAuthor">Author</label>
                                    <input id="bookAuthor" name="author" value="" placeholder="Enter author name" autocomplete="off">
                                </div>

                                <div class="book-field-row">
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookIsbn">ISBN</label>
                                        <input id="bookIsbn" name="isbn" value="" placeholder="e.g. 978-0-123456-78-9" autocomplete="off">
                                    </div>
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookEdition">Edition</label>
                                        <input id="bookEdition" name="edition" value="" placeholder="e.g. 1st, 2nd, 3rd" autocomplete="off">
                                    </div>
                                </div>

                                <div class="book-field-row">
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookGenre">Genre <span class="req" aria-hidden="true">*</span></label>
                                        <div class="book-field-select">
                                            <input id="bookGenre" name="genre" value="" list="bookGenreList" placeholder="Select or type genre…" autocomplete="off">
                                            <datalist id="bookGenreList">
                                                <?php foreach ($book_genre_datalist as $g): ?>
                                                    <option value="<?= h($g) ?>"></option>
                                                <?php endforeach; ?>
                                            </datalist>
                                            <span class="book-field-select__chev" aria-hidden="true"></span>
                                        </div>
                                    </div>
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookLanguage">Language <span class="req" aria-hidden="true">*</span></label>
                                        <div class="book-field-select">
                                            <input id="bookLanguage" name="language" value="" list="bookLanguageList" placeholder="Select language…" autocomplete="off">
                                            <datalist id="bookLanguageList">
                                                <?php foreach ($book_language_datalist as $ln): ?>
                                                    <option value="<?= h($ln) ?>"></option>
                                                <?php endforeach; ?>
                                            </datalist>
                                            <span class="book-field-select__chev" aria-hidden="true"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="book-field book-field--desc">
                                    <label class="book-field__label" for="bookDescription">Description</label>
                                    <div class="book-textarea-wrap">
                                        <textarea id="bookDescription" class="book-description-field" name="description" rows="4" maxlength="500" placeholder="Add a brief description about the book…"></textarea>
                                        <span class="book-textarea-count" id="bookDescCount" aria-live="polite">0 / 500 characters</span>
                                    </div>
                                </div>
                            </section>

                            <section class="book-modal-section book-modal-section--inventory" aria-labelledby="bookSectionInventoryTitle">
                                <h3 class="book-modal-section__title" id="bookSectionInventoryTitle">
                                    <span class="book-modal-section__num">3.</span>
                                    <span class="book-modal-section__icon" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                                    </span>
                                    Inventory
                                </h3>
                                <div class="book-field-row">
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookTotal">Total Copies <span class="req" aria-hidden="true">*</span></label>
                                        <input id="bookTotal" name="copies_total" type="number" min="1" max="9999" value="1" required>
                                    </div>
                                    <div class="book-field">
                                        <label class="book-field__label" for="bookStatus">Status <span class="req" aria-hidden="true">*</span></label>
                                        <div class="book-field-select book-field-select--status">
                                            <select id="bookStatus" name="status" required>
                                                <option value="active">Available</option>
                                                <option value="archived">Archived</option>
                                            </select>
                                            <span class="book-status-dot" id="bookStatusDot" data-status="active" aria-hidden="true"></span>
                                            <span class="book-field-select__chev" aria-hidden="true"></span>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <div class="book-modal-summary" id="bookSummaryPreview" aria-live="polite">
                        <div class="book-modal-summary__head">
                            <span class="book-modal-summary__icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                            </span>
                            <div>
                                <div class="book-modal-summary__title">Summary Preview</div>
                                <div class="book-modal-summary__sub muted">Review the book details before saving</div>
                            </div>
                        </div>
                        <div class="book-modal-summary__grid">
                            <div class="book-modal-summary__thumb" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            </div>
                            <dl class="book-summary-dl">
                                <div class="book-summary-item"><dt>Title</dt><dd id="sumTitle">—</dd></div>
                                <div class="book-summary-item"><dt>ISBN</dt><dd id="sumIsbn">—</dd></div>
                                <div class="book-summary-item"><dt>Genre</dt><dd id="sumGenre">—</dd></div>
                                <div class="book-summary-item"><dt>Language</dt><dd id="sumLanguage">—</dd></div>
                                <div class="book-summary-item"><dt>Edition</dt><dd id="sumEdition">—</dd></div>
                                <div class="book-summary-item"><dt>Copies</dt><dd id="sumCopies">—</dd></div>
                                <div class="book-summary-item"><dt>Status</dt><dd id="sumStatus">—</dd></div>
                            </dl>
                        </div>
                    </div>

                    <div class="book-modal-manage" id="bookModalManageBar" hidden>
                        <p class="book-modal-manage__meta muted" id="bookModalHeroStats"></p>
                        <button class="btn btn-sm btn-danger" type="button" id="bookModalBtnDelete">Delete book</button>
                    </div>
                </form>

                <div class="msg err book-modal-danger" id="bookModalDanger" style="display:none;"></div>

                <div class="book-modal-adjust" id="adjustWrap" style="display:none;">
                    <div class="hr" id="adjustHr"></div>
                    <h3 class="book-modal-adjust__title">Quick adjust availability</h3>
                    <p class="hint book-modal-adjust__hint">Use this only to correct mismatched counts.</p>
                    <form method="post" action="" id="adjustForm" class="book-modal-adjust__form">
                        <input type="hidden" name="action" value="adjust_available">
                        <input type="hidden" name="id" value="" id="adjustId">
                        <div class="book-field-row">
                            <div class="book-field">
                                <label class="book-field__label" for="bookAvail">Copies available</label>
                                <input id="bookAvail" name="copies_available" type="number" min="0" value="0">
                            </div>
                            <div class="book-field book-field--action">
                                <button class="btn btn-success" type="submit">Update availability</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <footer class="book-modal-footer">
                <p class="book-modal-footer__note" id="bookModalFooterNote">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    You can edit book details anytime after adding.
                </p>
                <div class="book-modal-footer__actions">
                    <button class="btn btn-ghost" type="button" data-close-book-modal>Cancel</button>
                    <button class="btn btn-primary book-modal-submit" type="submit" form="bookForm" id="bookSubmitBtn">
                        <span id="bookSubmitBtnLabel">Add Book</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    </button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Confirm modal -->
    <div class="modal" id="bookConfirmModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="bookConfirmTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="bookConfirmTitle">Confirm</h2>
                <button class="icon-btn" type="button" data-close-book-confirm aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="bookConfirmText" class="muted">Are you sure?</div>
                <form method="post" action="" id="bookConfirmForm" style="margin-top:12px;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="" id="bookConfirmId">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-book-confirm>Cancel</button>
                <button class="btn btn-danger" type="submit" form="bookConfirmForm">Delete</button>
            </div>
        </div>
    </div>

    <script src="../assets/app_ajax.js"></script>
    <script>
        (function () {
            function openModal(el) { el.classList.add('is-open'); el.setAttribute('aria-hidden', 'false'); }
            function closeModal(el) { el.classList.remove('is-open'); el.setAttribute('aria-hidden', 'true'); }

            const search = document.getElementById('inventorySearch');
            const clearBtn = document.getElementById('inventoryClear');
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

            const bookModal = document.getElementById('bookModal');
            const bookConfirmModal = document.getElementById('bookConfirmModal');
            const bookForm = document.getElementById('bookForm');
            const bookFormAction = document.getElementById('bookFormAction');
            const bookId = document.getElementById('bookId');
            const bookModalTitle = document.getElementById('bookModalTitle');
            const bookModalSubtitle = document.getElementById('bookModalSubtitle');
            const bookSubmitBtnLabel = document.getElementById('bookSubmitBtnLabel');
            const bookModalFooterNote = document.getElementById('bookModalFooterNote');
            const bookCoverPreview = document.getElementById('bookCoverPreview');
            const bookCoverPreviewWrap = document.getElementById('bookCoverPreviewWrap');
            const bookCoverDropEmpty = document.getElementById('bookCoverDropEmpty');
            const bookCoverDropzone = document.getElementById('bookCoverDropzone');
            const bookCoverEditHint = document.getElementById('bookCoverEditHint');
            const bookCoverInput = document.getElementById('bookCover');
            const bookModalHeroStats = document.getElementById('bookModalHeroStats');
            const bookModalManageBar = document.getElementById('bookModalManageBar');
            const bookModalBtnDelete = document.getElementById('bookModalBtnDelete');
            const bookModalDanger = document.getElementById('bookModalDanger');
            const bookDescCount = document.getElementById('bookDescCount');
            const bookStatusDot = document.getElementById('bookStatusDot');
            const sumTitle = document.getElementById('sumTitle');
            const sumIsbn = document.getElementById('sumIsbn');
            const sumGenre = document.getElementById('sumGenre');
            const sumLanguage = document.getElementById('sumLanguage');
            const sumEdition = document.getElementById('sumEdition');
            const sumCopies = document.getElementById('sumCopies');
            const sumStatus = document.getElementById('sumStatus');
            let currentEditBook = null;
            let coverObjectUrl = null;
            let hasCoverPreview = false;

            const adjustHr = document.getElementById('adjustHr');
            const adjustWrap = document.getElementById('adjustWrap');
            const adjustId = document.getElementById('adjustId');
            const bookAvail = document.getElementById('bookAvail');

            const fields = {
                title: document.getElementById('bookTitle'),
                author: document.getElementById('bookAuthor'),
                isbn: document.getElementById('bookIsbn'),
                genre: document.getElementById('bookGenre'),
                language: document.getElementById('bookLanguage'),
                edition: document.getElementById('bookEdition'),
                description: document.getElementById('bookDescription'),
                copies_total: document.getElementById('bookTotal'),
                status: document.getElementById('bookStatus'),
            };

            function summaryVal(v) {
                var s = String(v || '').trim();
                return s !== '' ? s : '—';
            }

            function statusLabel(v) {
                return v === 'archived' ? 'Archived' : 'Available';
            }

            function syncStatusDot() {
                if (!bookStatusDot || !fields.status) return;
                bookStatusDot.setAttribute('data-status', fields.status.value || 'active');
            }

            function syncCoverDropUi(showPreview) {
                hasCoverPreview = !!showPreview;
                if (bookCoverPreviewWrap) bookCoverPreviewWrap.hidden = !showPreview;
                if (bookCoverDropEmpty) bookCoverDropEmpty.hidden = !!showPreview;
                if (bookCoverDropzone) bookCoverDropzone.classList.toggle('has-preview', !!showPreview);
            }

            function updateDescCount() {
                if (!fields.description || !bookDescCount) return;
                var len = (fields.description.value || '').length;
                bookDescCount.textContent = len + ' / 500 characters';
                bookDescCount.classList.toggle('is-over', len >= 500);
            }

            function updateSummaryPreview() {
                if (sumTitle) sumTitle.textContent = summaryVal(fields.title && fields.title.value);
                if (sumIsbn) sumIsbn.textContent = summaryVal(fields.isbn && fields.isbn.value);
                if (sumGenre) sumGenre.textContent = summaryVal(fields.genre && fields.genre.value);
                if (sumLanguage) sumLanguage.textContent = summaryVal(fields.language && fields.language.value);
                if (sumEdition) sumEdition.textContent = summaryVal(fields.edition && fields.edition.value);
                if (sumCopies) sumCopies.textContent = summaryVal(fields.copies_total && fields.copies_total.value);
                if (sumStatus) sumStatus.textContent = fields.status ? statusLabel(fields.status.value) : '—';
                syncStatusDot();
            }

            function bindBookFormLivePreview() {
                Object.keys(fields).forEach(function (key) {
                    var el = fields[key];
                    if (!el) return;
                    el.addEventListener('input', updateSummaryPreview);
                    el.addEventListener('change', updateSummaryPreview);
                });
                if (fields.status) fields.status.addEventListener('change', syncStatusDot);
                if (fields.description) fields.description.addEventListener('input', updateDescCount);
            }
            bindBookFormLivePreview();

            function defaultCoverSrc() {
                return <?= json_encode(book_cover_src(null, 'Book'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            }

            function revokeCoverPreviewUrl() {
                if (coverObjectUrl) {
                    try { URL.revokeObjectURL(coverObjectUrl); } catch (e) {}
                    coverObjectUrl = null;
                }
            }

            function setCoverPreviewSrc(url, forceShow) {
                if (!bookCoverPreview) return;
                revokeCoverPreviewUrl();
                var src = url || defaultCoverSrc();
                bookCoverPreview.src = src;
                var show = !!forceShow || src !== defaultCoverSrc();
                syncCoverDropUi(show);
            }

            function applyCoverFile(file) {
                if (!file || !bookCoverPreview) return;
                if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) return;
                revokeCoverPreviewUrl();
                coverObjectUrl = URL.createObjectURL(file);
                bookCoverPreview.src = coverObjectUrl;
                syncCoverDropUi(true);
                try {
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    bookCoverInput.files = dt.files;
                } catch (e) {}
            }

            if (bookCoverInput) {
                bookCoverInput.addEventListener('change', function () {
                    var f = bookCoverInput.files && bookCoverInput.files[0];
                    if (f) applyCoverFile(f);
                });
            }

            if (bookCoverDropzone) {
                bookCoverDropzone.addEventListener('dragover', function (e) {
                    e.preventDefault();
                    bookCoverDropzone.classList.add('is-dragover');
                });
                bookCoverDropzone.addEventListener('dragleave', function () {
                    bookCoverDropzone.classList.remove('is-dragover');
                });
                bookCoverDropzone.addEventListener('drop', function (e) {
                    e.preventDefault();
                    bookCoverDropzone.classList.remove('is-dragover');
                    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                    if (f) applyCoverFile(f);
                });
            }

            function hideBookModalDanger() {
                if (!bookModalDanger) return;
                bookModalDanger.style.display = 'none';
                bookModalDanger.textContent = '';
            }

            function setModeAdd() {
                currentEditBook = null;
                if (bookModalManageBar) bookModalManageBar.hidden = true;
                if (bookCoverEditHint) bookCoverEditHint.hidden = true;
                if (bookModalFooterNote) bookModalFooterNote.hidden = false;
                hideBookModalDanger();
                bookForm.reset();
                bookFormAction.value = 'add';
                bookId.value = '';
                fields.copies_total.value = '1';
                fields.status.value = 'active';
                setCoverPreviewSrc(defaultCoverSrc(), false);
                bookModalTitle.textContent = 'Add New Book';
                if (bookModalSubtitle) bookModalSubtitle.textContent = 'Fill in the details below to add a new book to the library.';
                if (bookSubmitBtnLabel) bookSubmitBtnLabel.textContent = 'Add Book';
                if (bookModalHeroStats) bookModalHeroStats.textContent = '';
                adjustWrap.style.display = 'none';
                updateDescCount();
                updateSummaryPreview();
            }

            function setModeEdit(b) {
                currentEditBook = b;
                if (bookModalManageBar) bookModalManageBar.hidden = false;
                if (bookCoverEditHint) bookCoverEditHint.hidden = false;
                if (bookModalFooterNote) bookModalFooterNote.hidden = true;
                hideBookModalDanger();
                bookForm.reset();
                bookFormAction.value = 'edit';
                bookId.value = b.id;
                fields.title.value = b.title || '';
                fields.author.value = b.author || '';
                fields.isbn.value = b.isbn || '';
                fields.genre.value = b.genre || '';
                fields.language.value = b.language || '';
                fields.edition.value = b.edition || '';
                fields.description.value = b.description || '';
                fields.copies_total.value = String(b.copies_total || 1);
                fields.status.value = b.status || 'active';
                var coverSrc = b.cover_src || defaultCoverSrc();
                setCoverPreviewSrc(coverSrc, coverSrc !== defaultCoverSrc());
                bookModalTitle.textContent = 'Edit Book';
                if (bookModalSubtitle) {
                    var subBits = [b.title || 'Book'];
                    if (b.author) subBits.push(String(b.author));
                    bookModalSubtitle.textContent = subBits.join(' · ');
                }
                if (bookSubmitBtnLabel) bookSubmitBtnLabel.textContent = 'Save Changes';
                if (bookModalHeroStats) {
                    bookModalHeroStats.textContent = 'ID ' + String(b.id) + ' · Available ' + String(b.copies_available) + ' / ' + String(b.copies_total)
                        + ' · Borrowed now ' + String(b.borrowed_count != null ? b.borrowed_count : 0)
                        + (b.created_at ? ' · Added ' + String(b.created_at) : '');
                }

                adjustId.value = b.id;
                bookAvail.value = String(b.copies_available || 0);
                bookAvail.max = String(b.copies_total || 1);
                adjustWrap.style.display = '';
                updateDescCount();
                updateSummaryPreview();
            }

            function openConfirmDelete() {
                if (!currentEditBook) return;
                var idEl = document.getElementById('bookConfirmId');
                var txt = document.getElementById('bookConfirmText');
                if (!idEl || !txt) return;
                idEl.value = String(currentEditBook.id);
                txt.textContent = 'Delete "' + (currentEditBook.title || '') + '"? This cannot be undone.';
                openModal(bookConfirmModal);
            }

            document.querySelectorAll('tr.js-row-open-book').forEach(function (tr) {
                const data = tr.getAttribute('data-book');
                if (data) {
                    try { tr.setAttribute('data-book-id', (JSON.parse(data).id || '')); } catch (e) {}
                }
                function handler(e) {
                    const t = e.target;
                    if (t && (t.closest('button') || t.closest('a') || t.closest('form'))) return;
                    try {
                        const b = JSON.parse(tr.getAttribute('data-book'));
                        setModeEdit(b);
                        openModal(bookModal);
                    } catch (err) {}
                }
                tr.addEventListener('click', handler);
                tr.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handler(e);
                    }
                });
            });

            document.querySelectorAll('[data-open-book-modal]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (btn.getAttribute('data-open-book-modal') !== 'add') return;
                    setModeAdd();
                    openModal(bookModal);
                });
            });

            if (bookModalBtnDelete) {
                bookModalBtnDelete.addEventListener('click', function () {
                    openConfirmDelete();
                });
            }

            document.querySelectorAll('[data-close-book-modal]').forEach(function (b) {
                b.addEventListener('click', function () {
                    closeModal(bookModal);
                    revokeCoverPreviewUrl();
                    syncCoverDropUi(false);
                });
            });
            document.querySelectorAll('[data-close-book-confirm]').forEach(function (b) {
                b.addEventListener('click', function () { closeModal(bookConfirmModal); });
            });
            [bookModal, bookConfirmModal].forEach(function (m) {
                m.addEventListener('click', function (e) { if (e.target === m) closeModal(m); });
            });

            <?php if ($edit_book): ?>
                setModeEdit(<?= json_encode([
                    'id' => (int)$edit_book['id'],
                    'title' => (string)$edit_book['title'],
                    'author' => (string)($edit_book['author'] ?? ''),
                    'isbn' => (string)($edit_book['isbn'] ?? ''),
                    'description' => (string)($edit_book['description'] ?? ''),
                    'genre' => (string)($edit_book['genre'] ?? ''),
                    'language' => (string)($edit_book['language'] ?? ''),
                    'edition' => (string)($edit_book['edition'] ?? ''),
                    'copies_available' => (int)$edit_book['copies_available'],
                    'copies_total' => (int)$edit_book['copies_total'],
                    'status' => (string)$edit_book['status'],
                    'created_at' => (string)$edit_book['created_at'],
                    'borrowed_count' => 0,
                    'cover_src' => book_cover_src((string)($edit_book['cover_path'] ?? ''), (string)$edit_book['title']),
                ], JSON_UNESCAPED_SLASHES) ?>);
                openModal(bookModal);
            <?php endif; ?>

            function showAjaxFlash(text, isErr) {
                var el = document.getElementById('ajaxFlash');
                if (!el || !text) return;
                el.textContent = text;
                el.className = 'msg ' + (isErr ? 'err' : 'ok');
                el.style.display = '';
                el.setAttribute('role', isErr ? 'alert' : 'status');
            }

            function handleAjaxForm(form, onOk) {
                if (!form || !window.ajaxPostForm) return;
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    ajaxPostForm(form).then(function (data) {
                        if (data.ok) {
                            if (onOk) onOk(data);
                            else window.location.reload();
                        } else {
                            showAjaxFlash(data.message || data.error || 'Error', true);
                        }
                    }).catch(function () {
                        showAjaxFlash('Network error.', true);
                    });
                });
            }

            if (bookForm) {
                bookForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hideBookModalDanger();
                    ajaxPostForm(bookForm).then(function (data) {
                        if (data.ok) {
                            window.location.reload();
                        } else {
                            if (bookModalDanger) {
                                bookModalDanger.style.display = 'block';
                                bookModalDanger.textContent = data.message || data.error || 'Error';
                            } else {
                                showAjaxFlash(data.message || data.error || 'Error', true);
                            }
                        }
                    }).catch(function () {
                        showAjaxFlash('Network error.', true);
                    });
                });
            }

            handleAjaxForm(document.getElementById('adjustForm'), function () { window.location.reload(); });
            handleAjaxForm(document.getElementById('bookConfirmForm'), function () { window.location.reload(); });
        })();
    </script>
</body>
</html>

