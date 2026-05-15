<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app_session.php';
app_session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

if (isset($data['scan_mode']) && $data['scan_mode'] === 'manual') {
    $_SESSION['kiosk_scan_mode'] = 'manual';
} else {
    $_SESSION['kiosk_scan_mode'] = 'auto';
}

if (isset($data['mode'])) {
    $m = (string) $data['mode'];
    if ($m === 'exit' || $m === 'entry') {
        $_SESSION['kiosk_manual_mode'] = $m;
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
