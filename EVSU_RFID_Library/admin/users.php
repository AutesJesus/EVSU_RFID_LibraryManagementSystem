<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app_session.php';
app_session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/ajax_response.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

$pdo = get_pdo();

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

function normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, ['student', 'faculty', 'librarian'], true) ? $role : 'student';
}

function normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    return in_array($status, ['active', 'inactive'], true) ? $status : 'active';
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

/** Local SVG data URI when CDN / uploaded avatars fail to load in the browser. */
function user_avatar_img_fallback_data_uri(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
        . '<defs><linearGradient id="a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1c2333"/><stop offset="1" stop-color="#121722"/></linearGradient></defs>'
        . '<rect width="96" height="96" rx="48" fill="url(#a)"/>'
        . '<circle cx="48" cy="36" r="14" fill="rgba(230,237,243,.28)"/>'
        . '<path fill="rgba(230,237,243,.2)" d="M20 82c6-22 22-32 28-32s22 10 28 32"/></svg>';
    $cached = 'data:image/svg+xml;base64,' . base64_encode($svg);
    return $cached;
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

/** Programs for department suggestions; users may type any department or office name. */
$evsu_department_programs = [
    'Bachelor of Science in Information Technology (BSIT)',
    'Bachelor of Science in Civil Engineering (BSCE)',
    'Bachelor of Science in Electrical Engineering (BSEE)',
    'Bachelor of Science in Electronics Engineering (BSECE)',
    'Bachelor of Science in Mechanical Engineering (BSME)',
    'Bachelor of Science in Industrial Engineering (BSIE)',
    'Bachelor of Science in Chemical Engineering (BSChE)',
    'Bachelor of Science in Geodetic Engineering (BSGE)',
    'Bachelor of Science in Architecture (BSAr)',
    'Bachelor of Science in Interior Design (BSID)',
    'Bachelor of Science in Accountancy (BSA)',
    'Bachelor of Science in Entrepreneurship (BSE)',
    'Bachelor of Science in Marketing Management (BSM)',
    'Bachelor of Science in Office Administration (BSOA)',
    'Bachelor of Elementary Education (BEED)',
    'Bachelor of Secondary Education (BSEd)',
    'Bachelor of Physical Education (BPEd)',
    'Bachelor of Culture and Arts Education (BCAEd)',
    'Bachelor of Technology and Livelihood Education (BTLEd)',
    'Bachelor of Technical-Vocational Teacher Education (BTVTEd)',
    'Bachelor of Arts in English Language (BAEL)',
    'Bachelor of Science in Economics (BSEcon)',
    'Bachelor of Science in Mathematics (BSMath)',
    'Bachelor of Science in Statistics (BSStat)',
    'Bachelor of Science in Chemistry (BSChem)',
    'Bachelor of Science in Environmental Science (BSES)',
    'Bachelor of Science in Hospitality Management (BSHM)',
    'Bachelor of Science in Nutrition and Dietetics (BSND)',
    'Bachelor of Industrial Technology (BIT)',
    'Bachelor of Science in Industrial Technology (BSIndTech)',
    'Bachelor of Science in Agriculture (BSAgr)',
];

$flash = '';
$error = '';

// Actions: add, edit, delete, toggle_status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    try {
        if ($action === 'add' || $action === 'edit') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            $full_name = isset($_POST['full_name']) ? trim((string) $_POST['full_name']) : '';
            $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
            $rfid_tag = isset($_POST['rfid_tag']) ? trim((string) $_POST['rfid_tag']) : '';
            $role = normalize_role(isset($_POST['role']) ? (string) $_POST['role'] : '');
            $department = isset($_POST['department']) ? trim((string) $_POST['department']) : '';
            $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $status = normalize_status(isset($_POST['status']) ? (string) $_POST['status'] : 'active');

            if ($full_name === '' || $rfid_tag === '' || $department === '') {
                throw new RuntimeException('Full name, RFID tag, and department are required.');
            }

            $needs_login = in_array($role, ['student', 'faculty', 'librarian'], true);
            if ($needs_login) {
                if ($username === '') {
                    throw new RuntimeException('Username is required for portal login.');
                }
                if ($action === 'add' && $password === '') {
                    throw new RuntimeException('Password is required when adding a new user.');
                }
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (full_name, email, rfid_tag, role, department, username, password, avatar_path, status)
                     VALUES (:full_name, :email, :rfid_tag, :role, :department, :username, :password, :avatar_path, :status)'
                );

                $password_hash_or_null = null;
                $username_or_null = null;
                if ($needs_login) {
                    $username_or_null = $username;
                    $password_hash_or_null = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
                }

                $avatar_path = null;
                if (isset($_FILES['avatar']) && is_array($_FILES['avatar'])) {
                    $f = $_FILES['avatar'];
                    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('Avatar upload failed.');
                        }
                        $tmp = (string)($f['tmp_name'] ?? '');
                        $name = (string)($f['name'] ?? '');
                        if ($tmp === '' || !is_uploaded_file($tmp)) {
                            throw new RuntimeException('Invalid avatar upload.');
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
                        $filename = 'user_new_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
                        if (!move_uploaded_file($tmp, $absPath)) {
                            throw new RuntimeException('Failed to save uploaded avatar.');
                        }
                        $avatar_path = $relDir . '/' . $filename;
                    }
                }

                $stmt->execute([
                    'full_name' => $full_name,
                    'email' => $email !== '' ? $email : null,
                    'rfid_tag' => $rfid_tag,
                    'role' => $role,
                    'department' => $department,
                    'username' => $username_or_null,
                    'password' => $password_hash_or_null,
                    'avatar_path' => $avatar_path,
                    'status' => $status,
                ]);
                $flash = 'User added.';
            } else {
                if ($id <= 0) {
                    throw new RuntimeException('Missing user id.');
                }

                $stmtExisting = $pdo->prepare('SELECT id, role FROM users WHERE id = :id');
                $stmtExisting->execute(['id' => $id]);
                $existing = $stmtExisting->fetch();
                if ($existing === false) {
                    throw new RuntimeException('User not found.');
                }

                // If role is faculty/librarian and password is blank, keep existing password.
                $password_sql = '';
                $params = [
                    'id' => $id,
                    'full_name' => $full_name,
                    'email' => $email !== '' ? $email : null,
                    'rfid_tag' => $rfid_tag,
                    'role' => $role,
                    'department' => $department,
                    'username' => $needs_login ? $username : null,
                    'status' => $status,
                ];

                $avatar_sql = '';
                if (isset($_FILES['avatar']) && is_array($_FILES['avatar'])) {
                    $f = $_FILES['avatar'];
                    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                            throw new RuntimeException('Avatar upload failed.');
                        }
                        $tmp = (string)($f['tmp_name'] ?? '');
                        $name = (string)($f['name'] ?? '');
                        if ($tmp === '' || !is_uploaded_file($tmp)) {
                            throw new RuntimeException('Invalid avatar upload.');
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
                        $filename = 'user_' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
                        if (!move_uploaded_file($tmp, $absPath)) {
                            throw new RuntimeException('Failed to save uploaded avatar.');
                        }
                        $avatar_sql = ', avatar_path = :avatar_path';
                        $params['avatar_path'] = $relDir . '/' . $filename;
                    }
                }

                if ($needs_login) {
                    if ($password !== '') {
                        $password_sql = ', password = :password';
                        $params['password'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                } else {
                    $password_sql = ', password = NULL';
                }

                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET full_name = :full_name,
                         email = :email,
                         rfid_tag = :rfid_tag,
                         role = :role,
                         department = :department,
                         username = :username,
                         status = :status
                         {$avatar_sql}
                         {$password_sql}
                     WHERE id = :id"
                );
                $stmt->execute($params);
                $flash = 'User updated.';
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing user id.');
            }
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $flash = 'User deleted.';
        } elseif ($action === 'toggle_status') {
            $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
            if ($id <= 0) {
                throw new RuntimeException('Missing user id.');
            }
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END
                 WHERE id = :id"
            );
            $stmt->execute(['id' => $id]);
            $flash = 'Status updated.';
        }
    } catch (Throwable $e) {
        // MySQL duplicate key errors land here too; show a friendly message.
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false) {
            $msg = 'Duplicate value detected (RFID tag or username already exists).';
        }
        $error = $msg;
    }

    if (ajax_is_requested()) {
        ajax_json_response($error === '', $flash, $error);
    }
}

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_user = null;
if ($edit_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $edit_id]);
    $edit_user = $stmt->fetch() ?: null;
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$role_f = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : '';
$status_f = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';

if (!in_array($role_f, ['', 'student', 'faculty', 'librarian'], true)) $role_f = '';
if (!in_array($status_f, ['', 'active', 'inactive'], true)) $status_f = '';

$whereParts = [];
$params = [];
if ($q !== '') {
    $whereParts[] = '(full_name LIKE :q OR email LIKE :q OR rfid_tag LIKE :q OR department LIKE :q OR role LIKE :q OR username LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($role_f !== '') {
    $whereParts[] = 'role = :role';
    $params['role'] = $role_f;
}
if ($status_f !== '') {
    $whereParts[] = 'status = :status';
    $params['status'] = $status_f;
}

$whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
$stmtUsers = $pdo->prepare(
    "SELECT *
     FROM users
     {$whereSql}
     ORDER BY created_at DESC, id DESC
     LIMIT 2000"
);
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll();

$admin_sidebar_avatar = admin_avatar_src($pdo, (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['admin_username'] ?? 'Admin'));

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users — Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Admin sidebar">
            <div class="admin-brand">
                <img class="admin-mark" width="38" height="38" alt="Admin profile picture" src="<?= h($admin_sidebar_avatar) ?>">
                <div>
                    <div class="admin-brand-title">EVSU Library</div>
                    <div class="admin-brand-sub">RFID Admin</div>
                </div>
            </div>
            <nav class="admin-nav" aria-label="Admin navigation">
                <a href="index.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.8V21h14V9.8"/></svg>
                            </span>
                            <span>Dashboard</span>
                        </span>
                        <small>Home</small>
                    </span>
                </a>
                <a class="is-active" href="users.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M16 11a4 4 0 1 0-8 0"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                            </span>
                            <span>Users</span>
                        </span>
                        <small>Manage</small>
                    </span>
                </a>
                <a href="inventory.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M7 3h10v18H7z"/><path d="M7 7h10"/></svg>
                            </span>
                            <span>Inventory</span>
                        </span>
                        <small>Books</small>
                    </span>
                </a>
                <a href="borrowings.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M7 4h10v16H7z"/><path d="M17 8h2a2 2 0 0 1 2 2v10H7"/></svg>
                            </span>
                            <span>Borrowing</span>
                        </span>
                        <small>Issue/Return</small>
                    </span>
                </a>
                <a href="logs.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                            </span>
                            <span>Logs</span>
                        </span>
                        <small>Entry/Exit</small>
                    </span>
                </a>
                <a href="../scan.php" target="_blank" rel="noopener">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 12h2"/><path d="M18 12h2"/><path d="M12 4v2"/><path d="M12 18v2"/><circle cx="12" cy="12" r="4"/></svg>
                            </span>
                            <span>Scanner</span>
                        </span>
                        <small>Kiosk</small>
                    </span>
                </a>
                <a href="profile.php">
                    <span class="nav-item">
                        <span class="nav-left">
                            <span class="nav-ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="8" r="4"/></svg>
                            </span>
                            <span>Profile</span>
                        </span>
                        <small>Account</small>
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

        <main class="admin-main admin-page-list">
            <div class="container">
                <header class="admin-topbar">
                    <div>
                        <h1>Manage Users</h1>
                        <div class="subtitle">Click a row to open a user — edit details, delete, or toggle status from the dialog.</div>
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
                    <section class="card users-directory inventory-card directory-list-card" aria-label="Users list">
                        <div class="card-body inventory-toolbar directory-list-toolbar">
                            <h2 class="card-title inventory-title">Users</h2>

                            <form method="get" action="" class="inventory-actionbar" role="search" aria-label="Users search">
                                <button class="btn btn-primary" type="button" data-open-user-modal="add">Add user</button>

                                <div class="inventory-search-wrap">
                                    <span class="inventory-search-ico" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M21 21l-4.3-4.3"/>
                                            <circle cx="11" cy="11" r="7"/>
                                        </svg>
                                    </span>
                                    <input
                                        id="usersSearch"
                                        class="inventory-search"
                                        name="q"
                                        placeholder="Search name, RFID, dept, email, or username"
                                        value="<?= h($q) ?>"
                                        autocomplete="off"
                                    >
                                </div>

                                <button class="btn btn-primary" type="submit">Apply</button>
                                <a
                                    id="usersClear"
                                    class="btn btn-ghost inventory-clear<?= $q === '' ? ' is-hidden' : '' ?>"
                                    href="users.php"
                                >Clear</a>
                            </form>

                            <nav class="inventory-tabs" aria-label="User filters">
                                <?php
                                    $mkRole = function (string $label, string $v) use ($q, $status_f, $role_f): void {
                                        $qs = [];
                                        if ($q !== '') $qs['q'] = $q;
                                        if ($status_f !== '') $qs['status'] = $status_f;
                                        if ($v !== '') $qs['role'] = $v;
                                        $is = ($v === '' && $role_f === '') || ($role_f === $v);
                                        $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                        echo '<a class="' . $cls . '" href="users.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                    };
                                    $mkStatus = function (string $label, string $v) use ($q, $role_f, $status_f): void {
                                        $qs = [];
                                        if ($q !== '') $qs['q'] = $q;
                                        if ($role_f !== '') $qs['role'] = $role_f;
                                        if ($v !== '') $qs['status'] = $v;
                                        $is = ($v === '' && $status_f === '') || ($status_f === $v);
                                        $cls = $is ? 'btn btn-sm btn-primary' : 'btn btn-sm';
                                        echo '<a class="' . $cls . '" href="users.php?' . h(http_build_query($qs)) . '">' . h($label) . '</a>';
                                    };
                                ?>
                                <span class="muted" style="font-weight:900; font-size:.82rem; margin-right:6px;">Role</span>
                                <?php
                                    $mkRole('All', '');
                                    $mkRole('Student', 'student');
                                    $mkRole('Faculty', 'faculty');
                                    $mkRole('Librarian', 'librarian');
                                ?>
                                <span class="muted" style="font-weight:900; font-size:.82rem; margin:0 6px 0 10px;">Status</span>
                                <?php
                                    $mkStatus('All', '');
                                    $mkStatus('Active', 'active');
                                    $mkStatus('Inactive', 'inactive');
                                ?>
                            </nav>
                        </div>
                        <div class="table-wrap directory-list-scroll">
                            <table class="users-directory-table directory-data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th class="users-col-photo">Photo</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>RFID</th>
                                        <th>Role</th>
                                        <th class="users-col-dept">Dept</th>
                                        <th class="users-col-user">Username</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$users): ?>
                                    <tr><td colspan="10" class="muted">No users yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $u): ?>
                                        <?php
                                            $row_avatar = public_avatar_src((string)($u['avatar_path'] ?? ''), (string)($u['full_name'] ?? 'User'));
                                            $row_un = trim((string)($u['username'] ?? ''));
                                        ?>
                                        <tr
                                            class="js-row-open-user users-table__row"
                                            tabindex="0"
                                            role="button"
                                            title="Open user"
                                            data-user='<?= h(json_encode([
                                                'id' => (int)$u['id'],
                                                'full_name' => (string)$u['full_name'],
                                                'email' => (string)($u['email'] ?? ''),
                                                'rfid_tag' => (string)$u['rfid_tag'],
                                                'role' => (string)$u['role'],
                                                'department' => (string)$u['department'],
                                                'username' => (string)($u['username'] ?? ''),
                                                'status' => (string)$u['status'],
                                                'created_at' => (string)$u['created_at'],
                                                'avatar_src' => $row_avatar,
                                            ], JSON_UNESCAPED_SLASHES)) ?>'
                                        >
                                            <td><?= (int) $u['id'] ?></td>
                                            <td class="users-col-photo">
                                                <img
                                                    class="users-table-avatar"
                                                    src="<?= h($row_avatar) ?>"
                                                    alt=""
                                                    width="40"
                                                    height="40"
                                                    loading="lazy"
                                                    decoding="async"
                                                    referrerpolicy="no-referrer"
                                                    onerror="this.onerror=null;this.src='<?= h(user_avatar_img_fallback_data_uri()) ?>'"
                                                >
                                            </td>
                                            <td><?= h((string)$u['full_name']) ?></td>
                                            <td><?= h((string)($u['email'] ?? '')) ?></td>
                                            <td><?= h((string)$u['rfid_tag']) ?></td>
                                            <td><span class="pill info"><?= h((string)$u['role']) ?></span></td>
                                            <td class="users-col-dept"><?= h((string)$u['department']) ?></td>
                                            <td class="users-col-user"><?= $row_un !== '' ? h($row_un) : '<span class="users-cell-empty">—</span>' ?></td>
                                            <td>
                                                <?php if ($u['status'] === 'active'): ?>
                                                    <span class="pill ok">active</span>
                                                <?php else: ?>
                                                    <span class="pill bad">inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h((string)$u['created_at']) ?></td>
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

    <!-- User Add/Edit modal -->
    <div class="modal" id="userModal" aria-hidden="true">
        <div class="modal-panel modal-panel-user" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="userModalTitle">User</h2>
                <button class="icon-btn" type="button" data-close-modal aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body modal-body-user">
                <form method="post" action="" enctype="multipart/form-data" id="userForm">
                    <input type="hidden" name="action" value="add" id="userFormAction">
                    <input type="hidden" name="id" value="" id="userId">

                    <div class="user-modal-hero">
                        <div class="user-modal-hero__visual">
                            <img
                                src="<?= h(ui_avatar_url('User')) ?>"
                                alt=""
                                class="user-modal-hero__avatar"
                                id="userAvatarPreview"
                                width="96"
                                height="96"
                                referrerpolicy="no-referrer"
                                onerror="this.onerror=null;this.src=<?= json_encode(user_avatar_img_fallback_data_uri(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>"
                            >
                        </div>
                        <div class="user-modal-hero__identity">
                            <div class="user-modal-hero__name" id="userModalHeroName">New user</div>
                            <div class="user-modal-hero__sub muted" id="userModalHeroSub">Add details below</div>
                        </div>
                        <label for="userAvatar" class="user-modal-hero__label">Profile picture</label>
                        <input id="userAvatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp" class="user-modal-hero__file">
                        <p class="hint user-modal-hero__hint">Optional — JPG, PNG, or WEBP, max 2MB. Leave empty to keep a generated avatar.</p>
                    </div>

                    <div class="user-modal-actions" id="userModalManageBar" hidden>
                        <button class="btn btn-sm btn-danger" type="button" id="userModalBtnDelete">Delete user</button>
                        <button class="btn btn-sm" type="button" id="userModalBtnToggle">Toggle active / inactive</button>
                    </div>

                    <label for="userFullName">Full name</label>
                    <input id="userFullName" name="full_name" required value="">

                    <label for="userEmail">Email</label>
                    <input id="userEmail" name="email" type="email" value="">

                    <div class="row">
                        <div>
                            <label for="userRole">Role</label>
                            <select id="userRole" name="role" required>
                                <option value="student">Student</option>
                                <option value="faculty">Faculty</option>
                                <option value="librarian">Librarian</option>
                            </select>
                        </div>
                        <div>
                            <label for="userStatus">Status</label>
                            <select id="userStatus" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <label for="userDept">Department / program</label>
                    <input
                        id="userDept"
                        name="department"
                        required
                        value=""
                        list="evsuDepartmentPrograms"
                        autocomplete="off"
                        placeholder="Choose from list or type any department…"
                    >
                    <datalist id="evsuDepartmentPrograms">
                        <?php foreach ($evsu_department_programs as $prog): ?>
                            <option value="<?= h($prog) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="hint">Suggestions include EVSU programs; you can enter any department or unit.</div>

                    <label for="userUsername">Username (portal login)</label>
                    <input id="userUsername" name="username" value="">
                    <div class="hint">Used for student, faculty, and librarian sign-in.</div>

                    <label for="userPassword">Password</label>
                    <input id="userPassword" name="password" type="password" value="">
                    <div class="hint" id="userPasswordHint">Required when adding a new user.</div>

                    <div class="user-rfid-section" id="userRfidBlock" aria-labelledby="userRfidSectionTitle">
                        <h3 class="user-rfid-section-title" id="userRfidSectionTitle">RFID tag</h3>
                        <p class="hint user-rfid-lead" id="userRfidLead">Use the button below to open the scanner window. Your reader will type into that field so the tag is captured reliably.</p>
                        <label for="userRfid" class="user-rfid-value-label">Captured RFID</label>
                        <input
                            id="userRfid"
                            name="rfid_tag"
                            type="text"
                            readonly
                            tabindex="-1"
                            autocomplete="off"
                            class="user-rfid-display"
                            placeholder="(not scanned yet)"
                            value=""
                            aria-describedby="userRfidLead"
                        >
                        <button class="btn btn-primary user-rfid-scan-cta" type="button" id="userRfidScanBtn">
                            Click to scan new user RFID
                        </button>
                    </div>
                </form>

                <div class="msg err" id="userModalDanger" style="display:none; margin-top:12px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Cancel</button>
                <button class="btn btn-primary" type="submit" form="userForm" id="userSubmitBtn">Save</button>
            </div>
        </div>
    </div>

    <!-- RFID capture (opened from user modal) -->
    <div class="modal modal-layered" id="rfidScanModal" aria-hidden="true">
        <div class="modal-panel modal-panel-rfid-scan" role="dialog" aria-modal="true" aria-labelledby="rfidScanTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="rfidScanTitle">Scan RFID tag</h2>
                <button class="icon-btn" type="button" data-close-rfid-scan aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <p class="hint user-rfid-scan-intro">Focus stays in the field below. Scan the card now, or type the tag ID and press Enter.</p>
                <label for="rfidScanInput">RFID input</label>
                <input id="rfidScanInput" type="text" autocomplete="off" spellcheck="false" inputmode="text" class="user-rfid-scan-field" placeholder="Waiting for scan…">
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-rfid-scan>Cancel</button>
                <button class="btn btn-primary" type="button" id="rfidScanApply">Use this tag</button>
            </div>
        </div>
    </div>

    <!-- Confirm modal for delete/toggle -->
    <div class="modal" id="confirmModal" aria-hidden="true">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
            <div class="modal-header">
                <h2 class="modal-title" id="confirmTitle">Confirm</h2>
                <button class="icon-btn" type="button" data-close-confirm aria-label="Close">
                    <svg viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <div id="confirmText" class="muted">Are you sure?</div>
                <form method="post" action="" id="confirmForm" style="margin-top:12px;">
                    <input type="hidden" name="action" value="" id="confirmAction">
                    <input type="hidden" name="id" value="" id="confirmId">
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-confirm>Cancel</button>
                <button class="btn btn-danger" type="submit" form="confirmForm" id="confirmBtn">Confirm</button>
            </div>
        </div>
    </div>

    <script src="../assets/app_ajax.js"></script>
    <script>
        (function () {
            const search = document.getElementById('usersSearch');
            const clearBtn = document.getElementById('usersClear');
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

            const userModal = document.getElementById('userModal');
            const confirmModal = document.getElementById('confirmModal');
            const rfidScanModal = document.getElementById('rfidScanModal');
            const rfidScanInput = document.getElementById('rfidScanInput');
            const rfidScanApply = document.getElementById('rfidScanApply');
            const userRfidScanBtn = document.getElementById('userRfidScanBtn');
            const userModalDanger = document.getElementById('userModalDanger');
            const userModalManageBar = document.getElementById('userModalManageBar');
            const userModalBtnDelete = document.getElementById('userModalBtnDelete');
            const userModalBtnToggle = document.getElementById('userModalBtnToggle');
            let currentEditUser = null;

            function openConfirmForUser(mode) {
                if (!currentEditUser) return;
                const confirmText = document.getElementById('confirmText');
                const confirmAction = document.getElementById('confirmAction');
                const confirmId = document.getElementById('confirmId');
                const confirmBtn = document.getElementById('confirmBtn');
                if (!confirmText || !confirmAction || !confirmId || !confirmBtn) return;
                confirmId.value = String(currentEditUser.id);
                if (mode === 'delete') {
                    confirmAction.value = 'delete';
                    confirmText.textContent = 'Delete user "' + (currentEditUser.full_name || '') + '"? This cannot be undone.';
                    confirmBtn.textContent = 'Delete';
                    confirmBtn.classList.add('btn-danger');
                } else {
                    confirmAction.value = 'toggle_status';
                    confirmText.textContent = 'Toggle active/inactive for "' + (currentEditUser.full_name || '') + '"?';
                    confirmBtn.textContent = 'Toggle';
                    confirmBtn.classList.add('btn-danger');
                }
                openModal(confirmModal);
            }

            function openModal(el) {
                el.classList.add('is-open');
                el.setAttribute('aria-hidden', 'false');
            }
            function closeModal(el) {
                el.classList.remove('is-open');
                el.setAttribute('aria-hidden', 'true');
                if (el === userModal && rfidScanModal && rfidScanModal.classList.contains('is-open')) {
                    rfidScanModal.classList.remove('is-open');
                    rfidScanModal.setAttribute('aria-hidden', 'true');
                }
            }

            function openRfidScanModal() {
                if (!rfidScanModal || !rfidScanInput) return;
                rfidScanInput.value = '';
                openModal(rfidScanModal);
                window.setTimeout(function () {
                    rfidScanInput.focus();
                    rfidScanInput.select();
                }, 60);
            }

            function closeRfidScanModal() {
                if (!rfidScanModal) return;
                closeModal(rfidScanModal);
            }

            function applyRfidFromScan() {
                if (!rfidScanInput || !fields.rfid_tag) return;
                var v = String(rfidScanInput.value || '').replace(/[\r\n\x00]/g, '').trim();
                if (!v) {
                    rfidScanInput.focus();
                    return;
                }
                fields.rfid_tag.value = v;
                closeRfidScanModal();
                if (userRfidScanBtn) userRfidScanBtn.focus();
            }

            const userForm = document.getElementById('userForm');
            const userFormAction = document.getElementById('userFormAction');
            const userId = document.getElementById('userId');
            const userModalTitle = document.getElementById('userModalTitle');
            const userSubmitBtn = document.getElementById('userSubmitBtn');
            const userAvatarPreview = document.getElementById('userAvatarPreview');
            const userModalHeroName = document.getElementById('userModalHeroName');
            const userModalHeroSub = document.getElementById('userModalHeroSub');
            const userPasswordHint = document.getElementById('userPasswordHint');

            const fields = {
                full_name: document.getElementById('userFullName'),
                email: document.getElementById('userEmail'),
                rfid_tag: document.getElementById('userRfid'),
                role: document.getElementById('userRole'),
                status: document.getElementById('userStatus'),
                department: document.getElementById('userDept'),
                username: document.getElementById('userUsername'),
                password: document.getElementById('userPassword'),
            };

            function setFormModeAdd() {
                currentEditUser = null;
                if (userModalManageBar) userModalManageBar.hidden = true;
                userForm.reset();
                userFormAction.value = 'add';
                userId.value = '';
                fields.role.value = 'student';
                fields.status.value = 'active';
                fields.password.value = '';
                userModalTitle.textContent = 'Add user';
                userSubmitBtn.textContent = 'Add user';
                userPasswordHint.textContent = 'Required when adding a new user.';
                userAvatarPreview.src = <?= json_encode(ui_avatar_url('User')) ?>;
                if (userModalHeroName) userModalHeroName.textContent = 'New user';
                if (userModalHeroSub) userModalHeroSub.textContent = 'Add details below';
                if (userRfidScanBtn) userRfidScanBtn.textContent = 'Click to scan new user RFID';
                if (userModalDanger) {
                    userModalDanger.style.display = 'none';
                    userModalDanger.textContent = '';
                }
            }

            function setFormModeEdit(u) {
                currentEditUser = u;
                if (userModalManageBar) userModalManageBar.hidden = false;
                userForm.reset();
                userFormAction.value = 'edit';
                userId.value = u.id;
                fields.full_name.value = u.full_name || '';
                fields.email.value = u.email || '';
                fields.rfid_tag.value = u.rfid_tag || '';
                fields.role.value = u.role || 'student';
                fields.status.value = u.status || 'active';
                fields.department.value = u.department || '';
                fields.username.value = u.username || '';
                fields.password.value = '';
                userModalTitle.textContent = 'Edit user';
                userSubmitBtn.textContent = 'Save changes';
                userPasswordHint.textContent = 'Leave blank to keep the current password.';
                userAvatarPreview.src = u.avatar_src || <?= json_encode(ui_avatar_url('User')) ?>;
                if (userModalHeroName) userModalHeroName.textContent = u.full_name || 'User';
                if (userModalHeroSub) {
                    var bits = [];
                    if (u.role) bits.push(String(u.role));
                    if (u.department) bits.push(String(u.department));
                    userModalHeroSub.textContent = bits.length ? bits.join(' · ') : '';
                }
                if (userRfidScanBtn) userRfidScanBtn.textContent = 'Click to scan or replace RFID';
                if (userModalDanger) {
                    userModalDanger.style.display = 'none';
                    userModalDanger.textContent = '';
                }
            }

            function getUserById(id) {
                const row = document.querySelector('tr.js-row-open-user[data-user-id="' + id + '"]');
                if (!row) return null;
                try { return JSON.parse(row.getAttribute('data-user')); } catch (e) { return null; }
            }

            // row click opens a view modal by reusing edit modal in read-like state (still editable)
            document.querySelectorAll('tr.js-row-open-user').forEach(function (tr) {
                const data = tr.getAttribute('data-user');
                if (data) {
                    try { tr.setAttribute('data-user-id', (JSON.parse(data).id || '')); } catch (e) {}
                }

                function handler(e) {
                    const t = e.target;
                    if (t && (t.closest('button') || t.closest('a') || t.closest('form'))) return;
                    try {
                        const u = JSON.parse(tr.getAttribute('data-user'));
                        setFormModeEdit(u);
                        openModal(userModal);
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

            if (userModalBtnDelete) {
                userModalBtnDelete.addEventListener('click', function () {
                    openConfirmForUser('delete');
                });
            }
            if (userModalBtnToggle) {
                userModalBtnToggle.addEventListener('click', function () {
                    openConfirmForUser('toggle');
                });
            }

            document.querySelectorAll('[data-open-user-modal]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (btn.getAttribute('data-open-user-modal') !== 'add') return;
                    setFormModeAdd();
                    openModal(userModal);
                });
            });

            document.querySelectorAll('[data-close-modal]').forEach(function (b) {
                b.addEventListener('click', function () { closeModal(userModal); });
            });
            document.querySelectorAll('[data-close-confirm]').forEach(function (b) {
                b.addEventListener('click', function () { closeModal(confirmModal); });
            });
            if (confirmModal) {
                confirmModal.addEventListener('click', function (e) {
                    if (e.target === confirmModal) closeModal(confirmModal);
                });
            }
            if (rfidScanModal) {
                rfidScanModal.addEventListener('click', function (e) {
                    if (e.target === rfidScanModal) closeRfidScanModal();
                });
            }

            if (userRfidScanBtn) {
                userRfidScanBtn.addEventListener('click', function () {
                    if (userModalDanger) {
                        userModalDanger.style.display = 'none';
                        userModalDanger.textContent = '';
                    }
                    openRfidScanModal();
                });
            }
            if (rfidScanApply) rfidScanApply.addEventListener('click', applyRfidFromScan);
            document.querySelectorAll('[data-close-rfid-scan]').forEach(function (b) {
                b.addEventListener('click', closeRfidScanModal);
            });
            if (rfidScanInput) {
                rfidScanInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyRfidFromScan();
                    }
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

            if (userForm) {
                userForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var tag = String(fields.rfid_tag.value || '').replace(/[\r\n\x00]/g, '').trim();
                    if (!tag) {
                        if (userModalDanger) {
                            userModalDanger.style.display = 'block';
                            userModalDanger.textContent = 'Scan an RFID tag with the button below before saving.';
                        }
                        if (userRfidScanBtn) userRfidScanBtn.focus();
                        return;
                    }
                    ajaxPostForm(userForm).then(function (data) {
                        if (data.ok) {
                            window.location.reload();
                        } else {
                            showAjaxFlash(data.message || data.error || 'Error', true);
                        }
                    }).catch(function () {
                        showAjaxFlash('Network error.', true);
                    });
                });
            }

            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (rfidScanModal && rfidScanModal.classList.contains('is-open')) {
                    e.preventDefault();
                    closeRfidScanModal();
                }
            });

            // If legacy ?edit= was used, open modal with that user preloaded
            <?php if ($edit_user): ?>
                setFormModeEdit(<?= json_encode([
                    'id' => (int)$edit_user['id'],
                    'full_name' => (string)$edit_user['full_name'],
                    'email' => (string)($edit_user['email'] ?? ''),
                    'rfid_tag' => (string)$edit_user['rfid_tag'],
                    'role' => (string)$edit_user['role'],
                    'department' => (string)$edit_user['department'],
                    'username' => (string)($edit_user['username'] ?? ''),
                    'status' => (string)$edit_user['status'],
                    'created_at' => (string)$edit_user['created_at'],
                    'avatar_src' => public_avatar_src((string)($edit_user['avatar_path'] ?? ''), (string)($edit_user['full_name'] ?? 'User')),
                ], JSON_UNESCAPED_SLASHES) ?>);
                openModal(userModal);
            <?php endif; ?>

            handleAjaxForm(document.getElementById('confirmForm'), function () {
                window.location.reload();
            });
        })();
    </script>
</body>
</html>

