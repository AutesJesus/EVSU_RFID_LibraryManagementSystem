<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$portal_mobile_brand = isset($portal_mobile_brand) && (string) $portal_mobile_brand !== ''
    ? (string) $portal_mobile_brand
    : 'EVSU Library';
?>
        <header class="portal-mobile-bar" aria-label="Mobile navigation">
            <button type="button" class="portal-menu-btn" id="portalMenuBtn" aria-expanded="false" aria-controls="portalSidebar" aria-label="Open menu">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>
            </button>
            <span class="portal-mobile-brand"><?= h($portal_mobile_brand) ?></span>
        </header>
        <div class="portal-sidebar-backdrop" id="portalSidebarBackdrop" hidden></div>
