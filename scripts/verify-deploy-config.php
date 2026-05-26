<?php

declare(strict_types=1);

/**
 * CI guard: fail the deploy job if production db.local.php was not generated correctly.
 */

$path = dirname(__DIR__) . '/config/db.local.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing config/db.local.php — DB_HOST secret may be empty; ci-write-config.php did not run.\n");
    exit(1);
}

require_once $path;

foreach (['DB_HOST', 'DB_USER', 'DB_NAME'] as $const) {
    if (!defined($const) || constant($const) === '') {
        fwrite(STDERR, "config/db.local.php must define {$const}.\n");
        exit(1);
    }
}

$host = strtolower((string) DB_HOST);
if (in_array($host, ['127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "DB_HOST must not be 127.0.0.1. Use the MySQL host from Hostinger hPanel (often localhost).\n");
    exit(1);
}

if (DB_USER === 'root') {
    fwrite(STDERR, "DB_USER must be the Hostinger MySQL user (u123456789_...), not root.\n");
    exit(1);
}

if (!defined('DB_AUTO_CREATE') || DB_AUTO_CREATE !== false) {
    fwrite(STDERR, "DB_AUTO_CREATE must be false in generated db.local.php for shared hosting.\n");
    exit(1);
}

fwrite(STDOUT, "Deploy config OK: host=" . DB_HOST . ", db=" . DB_NAME . ", user=" . DB_USER . "\n");
