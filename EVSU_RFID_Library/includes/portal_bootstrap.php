<?php
declare(strict_types=1);

require_once __DIR__ . '/app_session.php';
app_session_start();

require_once __DIR__ . '/../db.php';

/**
 * @return bool
 */
function portal_is_staff(): bool
{
    return defined('STAFF_PORTAL') && STAFF_PORTAL;
}

function portal_asset(string $relativePath): string
{
    $relativePath = ltrim($relativePath, '/');
    if (portal_is_staff()) {
        return '../admin/' . $relativePath;
    }

    return $relativePath;
}

function portal_current_page(): string
{
    if (defined('STAFF_PAGE') && (string) STAFF_PAGE !== '') {
        return (string) STAFF_PAGE;
    }

    $base = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $map = [
        'index' => 'dashboard',
        'borrowings' => 'borrowings',
        'logs' => 'logs',
        'inventory' => 'inventory',
        'profile' => 'profile',
        'users' => 'users',
    ];

    return $map[$base] ?? $base;
}

function portal_nav_active(string $page): string
{
    return portal_current_page() === $page ? ' is-active' : '';
}

/**
 * Initialize session, PDO, and sidebar identity for admin or staff portal.
 */
function portal_bootstrap(): void
{
    global $pdo, $admin_id, $admin_username, $admin_avatar_src;

    if (portal_is_staff()) {
        require_once __DIR__ . '/staff_auth.php';
        $page = defined('STAFF_PAGE') ? (string) STAFF_PAGE : '';
        staff_require_page($page);
        $pdo = get_pdo();
        staff_load_sidebar_identity($pdo);
        $admin_id = 0;

        return;
    }

    if (empty($_SESSION['admin_id'])) {
        header('Location: ../login.php');
        exit;
    }

    $pdo = get_pdo();
    $admin_id = (int) $_SESSION['admin_id'];
    $admin_username = (string) ($_SESSION['admin_username'] ?? 'Admin');

    if (!function_exists('portal_admin_public_avatar_src')) {
        function portal_admin_public_avatar_src(?string $avatar_path, string $fallback_name): string
        {
            if ($avatar_path !== null && $avatar_path !== '') {
                return '../' . ltrim($avatar_path, '/');
            }

            $q = http_build_query([
                'name' => $fallback_name,
                'rounded' => 'true',
                'background' => '0D1117',
                'color' => 'E6EDF3',
                'bold' => 'true',
                'size' => '96',
                'format' => 'png',
            ]);

            return 'https://ui-avatars.com/api/?' . $q;
        }
    }

    $stmtAdmin = $pdo->prepare('SELECT username, avatar_path FROM admins WHERE id = :id');
    $stmtAdmin->execute(['id' => $admin_id]);
    $adminRow = $stmtAdmin->fetch() ?: [];
    $admin_username = (string) ($adminRow['username'] ?? $admin_username);
    $admin_avatar_src = portal_admin_public_avatar_src(
        isset($adminRow['avatar_path']) ? (string) $adminRow['avatar_path'] : null,
        $admin_username
    );
}
