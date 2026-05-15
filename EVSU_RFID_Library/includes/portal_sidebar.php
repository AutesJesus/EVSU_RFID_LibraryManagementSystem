<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$portal_staff = portal_is_staff();
$portal_role = $portal_staff ? staff_current_role() : 'admin';
$portal_sub = $portal_staff ? staff_portal_subtitle() : 'RFID Admin';
$cur = portal_current_page();

$show_users = !$portal_staff;
$show_inventory = !$portal_staff || $portal_role === 'librarian';
$show_borrowing = true;
$show_logs = true;
$show_scanner = !$portal_staff || $portal_role === 'librarian';
$show_profile = true;
$profile_href = $portal_staff ? 'profile.php' : 'profile.php';
$logout_href = $portal_staff ? 'logout.php' : 'logout.php';
$scan_href = $portal_staff ? '../scan.php' : '../scan.php';
?>
        <aside class="admin-sidebar" aria-label="<?= $portal_staff ? 'Staff sidebar' : 'Admin sidebar' ?>">
            <div class="admin-brand">
                <img class="admin-mark" width="38" height="38" alt="Profile picture" src="<?= h((string) ($admin_avatar_src ?? '')) ?>">
                <div>
                    <div class="admin-brand-title">EVSU Library</div>
                    <div class="admin-brand-sub"><?= h($portal_sub) ?></div>
                </div>
            </div>

            <nav class="admin-nav" aria-label="<?= $portal_staff ? 'Staff navigation' : 'Admin navigation' ?>">
                <a class="<?= portal_nav_active('dashboard') ?>" href="index.php">
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
                <?php if ($show_users): ?>
                <a class="<?= portal_nav_active('users') ?>" href="users.php">
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
                <?php endif; ?>
                <?php if ($show_inventory): ?>
                <a class="<?= portal_nav_active('inventory') ?>" href="inventory.php">
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
                <?php endif; ?>
                <?php if ($show_borrowing): ?>
                <a class="<?= portal_nav_active('borrowings') ?>" href="borrowings.php">
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
                <?php endif; ?>
                <?php if ($show_logs): ?>
                <a class="<?= portal_nav_active('logs') ?>" href="logs.php">
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
                <?php endif; ?>
                <?php if ($show_scanner): ?>
                <a href="<?= h($scan_href) ?>"<?= $portal_staff ? '' : ' target="_blank" rel="noopener"' ?>>
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
                <?php endif; ?>
                <?php if ($show_profile): ?>
                <a class="<?= portal_nav_active('profile') ?>" href="<?= h($profile_href) ?>">
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
                <?php endif; ?>
                <a href="<?= h($logout_href) ?>">
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
