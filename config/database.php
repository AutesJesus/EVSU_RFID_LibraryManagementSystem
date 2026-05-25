<?php

declare(strict_types=1);

/** Local XAMPP defaults. Override in config/db.local.php (not committed). */
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

/** On shared hosting, create the database in hPanel and set this to false. */
if (!defined('DB_AUTO_CREATE')) {
    define('DB_AUTO_CREATE', true);
}

$dbLocal = __DIR__ . '/db.local.php';
if (is_file($dbLocal)) {
    require_once $dbLocal;
}
