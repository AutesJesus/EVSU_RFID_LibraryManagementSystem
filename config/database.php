<?php

declare(strict_types=1);

/**
 * Load config/db.local.php first (Hostinger / CI deploy), then XAMPP defaults if still unset.
 * db.local.php must be required before defaults — otherwise local defines are ignored.
 *
 * On production hosts, XAMPP fallbacks are disabled so a missing or empty db.local.php
 * cannot silently break the site after a partial manual upload.
 */
$dbLocal = __DIR__ . '/db.local.php';
if (is_file($dbLocal)) {
    require_once $dbLocal;
}

/**
 * True when the HTTP request targets shared hosting (not local XAMPP).
 */
function app_is_production_request(): bool
{
    if (getenv('APP_ENV') === 'production') {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }

    return str_contains($host, 'hostingersite.com')
        || str_contains($host, '.hostinger')
        || str_ends_with($host, '.hostinger.com');
}

/**
 * @return string|null Human-readable config error for production, or null when OK / local dev.
 */
function database_config_error(): ?string
{
    if (!app_is_production_request()) {
        return null;
    }

    $dbLocal = __DIR__ . '/db.local.php';
    if (!is_file($dbLocal)) {
        return 'Missing config/db.local.php on the server. Redeploy from GitHub with DB_HOST, DB_USER, DB_PASS, and DB_NAME secrets set in the repository (not DB_USERNAME / DB_DATABASE).';
    }

    if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_NAME')) {
        return 'config/db.local.php exists but does not define DB_HOST, DB_USER, and DB_NAME. Redeploy so CI can regenerate it from GitHub secrets.';
    }

    $host = strtolower((string) DB_HOST);
    if (in_array($host, ['127.0.0.1', '::1'], true)) {
        return 'Production database host must not be 127.0.0.1 (XAMPP default). In GitHub secrets set DB_HOST to the MySQL host from Hostinger hPanel (often localhost), then redeploy.';
    }

    if (DB_USER === 'root' && (!defined('DB_PASS') || DB_PASS === '')) {
        return 'Production cannot use MySQL user root with an empty password. Set GitHub secrets DB_USER and DB_PASS to the database user from Hostinger hPanel, then redeploy.';
    }

    if (DB_USER === '' || DB_NAME === '') {
        return 'DB_USER and DB_NAME must not be empty. Set GitHub secrets DB_USER and DB_NAME, then redeploy.';
    }

    return null;
}

$configError = database_config_error();
if ($configError !== null) {
    if (!defined('DATABASE_CONFIG_ERROR')) {
        define('DATABASE_CONFIG_ERROR', $configError);
    }
} elseif (!app_is_production_request()) {
    /** Local XAMPP defaults when db.local.php is missing (not committed). */
    if (!defined('DB_HOST')) {
        define('DB_HOST', '127.0.0.1');
    }
    if (!defined('DB_USER')) {
        define('DB_USER', 'root');
    }
    if (!defined('DB_PASS')) {
        define('DB_PASS', '');
    }
    if (!defined('DB_NAME')) {
        define('DB_NAME', 'evsu_rfid_library');
    }
}

/** On shared hosting, create the database in hPanel and set this to false in db.local.php. */
if (!defined('DB_AUTO_CREATE')) {
    define('DB_AUTO_CREATE', !app_is_production_request());
}
