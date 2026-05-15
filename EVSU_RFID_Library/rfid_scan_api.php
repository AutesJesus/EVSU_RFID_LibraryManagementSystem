<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function htext(string $value): string
{
    return trim(preg_replace('/[^A-Za-z0-9:_-]/', '', strtoupper($value)) ?? '');
}

$storeDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$storeFile = $storeDir . DIRECTORY_SEPARATOR . 'rfid_test_scans.json';

if (!is_dir($storeDir)) {
    @mkdir($storeDir, 0775, true);
}

if (!file_exists($storeFile)) {
    file_put_contents($storeFile, json_encode(['items' => []], JSON_UNESCAPED_SLASHES));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = [];

    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (!isset($data['uid']) && isset($_POST['uid'])) {
        $data['uid'] = (string) $_POST['uid'];
    }
    if (!isset($data['device']) && isset($_POST['device'])) {
        $data['device'] = (string) $_POST['device'];
    }

    $uid = isset($data['uid']) ? htext((string) $data['uid']) : '';
    $device = isset($data['device']) ? trim((string) $data['device']) : 'nano-rfid';

    if ($uid === '') {
        respond(422, ['ok' => false, 'message' => 'uid is required']);
    }

    $fp = fopen($storeFile, 'c+');
    if ($fp === false) {
        respond(500, ['ok' => false, 'message' => 'cannot open data file']);
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            respond(500, ['ok' => false, 'message' => 'cannot lock data file']);
        }

        rewind($fp);
        $currentRaw = stream_get_contents($fp);
        $current = json_decode((string) $currentRaw, true);
        if (!is_array($current) || !isset($current['items']) || !is_array($current['items'])) {
            $current = ['items' => []];
        }

        $entry = [
            'uid' => $uid,
            'device' => $device,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        array_unshift($current['items'], $entry);
        $current['items'] = array_slice($current['items'], 0, 50);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($current, JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    respond(200, ['ok' => true, 'saved' => $entry]);
}

$raw = file_get_contents($storeFile);
$data = json_decode((string) $raw, true);
if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    $data = ['items' => []];
}

respond(200, [
    'ok' => true,
    'count' => count($data['items']),
    'latest' => $data['items'][0] ?? null,
    'items' => $data['items'],
]);
