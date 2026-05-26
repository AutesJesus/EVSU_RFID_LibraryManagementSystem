<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query('SELECT 1');
    fwrite(STDOUT, "PDO OK\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'PDO FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
