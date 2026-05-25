<?php

declare(strict_types=1);

/**
 * Temporary deploy diagnostic — remove from production after the site works.
 * Does not print passwords or secret values.
 */

header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php_version' => PHP_VERSION,
    'php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'config_db_local' => is_file(__DIR__ . '/config/db.local.php'),
    'vendor_autoload' => is_file(__DIR__ . '/vendor/autoload.php'),
    'index_php' => is_file(__DIR__ . '/index.php'),
];

require_once __DIR__ . '/config/database.php';

$checks['db_host'] = defined('DB_HOST') ? DB_HOST : null;
$checks['db_name'] = defined('DB_NAME') ? DB_NAME : null;
$checks['db_user_set'] = defined('DB_USER') && DB_USER !== '';
$checks['db_auto_create'] = defined('DB_AUTO_CREATE') ? DB_AUTO_CREATE : null;

$dbError = null;
if ($checks['db_user_set'] && $checks['db_name']) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');
        $checks['database_connect'] = true;
    } catch (Throwable $e) {
        $checks['database_connect'] = false;
        $dbError = $e->getMessage();
    }
} else {
    $checks['database_connect'] = false;
    $dbError = 'DB_USER or DB_NAME is empty — check GitHub secrets (DB_USER, DB_PASS, DB_NAME, not DB_USERNAME/DB_DATABASE).';
}

$checks['all_ok'] = $checks['php_ok']
    && $checks['config_db_local']
    && $checks['vendor_autoload']
    && $checks['database_connect'];

$out = ['status' => $checks['all_ok'] ? 'ok' : 'fail', 'checks' => $checks];
if ($dbError !== null) {
    $out['database_error'] = $dbError;
}

http_response_code($checks['all_ok'] ? 200 : 500);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
