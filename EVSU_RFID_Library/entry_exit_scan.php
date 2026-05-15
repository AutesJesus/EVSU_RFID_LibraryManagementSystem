<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app_session.php';
app_session_start();

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/includes/kiosk_scan.php';

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if ($data === []) {
    $data = [
        'rfid_tag' => isset($_POST['rfid_tag']) ? (string) $_POST['rfid_tag'] : '',
        'scan_mode' => isset($_POST['scan_mode']) ? (string) $_POST['scan_mode'] : '',
        'mode' => isset($_POST['mode']) ? (string) $_POST['mode'] : '',
    ];
}

$pdo = get_pdo();
$result = kiosk_process_entry_exit_scan($pdo, $data, $_SESSION);

echo json_encode(
    [
        'ok' => true,
        'result' => $result,
    ],
    JSON_UNESCAPED_SLASHES
);
