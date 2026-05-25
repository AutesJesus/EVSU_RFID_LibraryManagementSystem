<?php
declare(strict_types=1);

require_once __DIR__ . '/app_session.php';

/** @var array<string, list<string>> */
const STAFF_PAGE_ACCESS = [
    'faculty' => ['dashboard', 'borrowings', 'logs', 'profile'],
    'librarian' => ['dashboard', 'inventory', 'borrowings', 'logs', 'profile'],
];

function staff_require_login(): void
{
    app_session_start();
    $role = (string) ($_SESSION['user_role'] ?? '');
    if (empty($_SESSION['user_id']) || !in_array($role, ['faculty', 'librarian'], true)) {
        header('Location: ../login.php');
        exit;
    }
}

function staff_current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function staff_current_role(): string
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    return in_array($role, ['faculty', 'librarian'], true) ? $role : 'faculty';
}

function staff_can_access_page(string $page): bool
{
    $role = staff_current_role();
    $allowed = STAFF_PAGE_ACCESS[$role] ?? [];

    return in_array($page, $allowed, true);
}

function staff_require_page(string $page): void
{
    staff_require_login();
    if ($page === '' || !staff_can_access_page($page)) {
        header('Location: index.php');
        exit;
    }
}

function staff_portal_subtitle(): string
{
    return staff_current_role() === 'librarian' ? 'Librarian portal' : 'Faculty portal';
}

function staff_role_label(): string
{
    return staff_current_role() === 'librarian' ? 'Librarian' : 'Faculty';
}

/**
 * Sidebar identity vars: $admin_avatar_src, $admin_username (display name).
 */
function staff_load_sidebar_identity(PDO $pdo): void
{
    $uid = staff_current_user_id();
    $stmt = $pdo->prepare(
        'SELECT full_name, username, avatar_path FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $uid]);
    $row = $stmt->fetch() ?: [];

    $name = (string) ($row['full_name'] ?? ($_SESSION['user_full_name'] ?? 'Staff'));
    $GLOBALS['admin_username'] = $name;
    $GLOBALS['admin_avatar_src'] = staff_public_avatar_src(
        isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
        $name
    );
}

function staff_ui_avatar_url(string $name): string
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

function staff_public_avatar_src(?string $avatar_path, string $fallback_name): string
{
    if ($avatar_path !== null && $avatar_path !== '') {
        $p = trim($avatar_path);
        if (preg_match('#^https?://#i', $p) === 1 || str_starts_with($p, '//')) {
            return $p;
        }
        return app_public_path($p);
    }

    return staff_ui_avatar_url($fallback_name);
}

// Back-compat with older includes
function faculty_require_login(): void
{
    staff_require_login();
}

function faculty_current_user_id(): int
{
    return staff_current_user_id();
}

function faculty_current_role(): string
{
    return staff_current_role();
}
