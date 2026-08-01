<?php
// EVE D-Scan cache API: save scan data, return short code
// Keeps the most recent 100 cache entries, no time limit
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheDir = __DIR__ . '/cache';

// Save scan data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data || empty($data['scan'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing scan data']);
        exit;
    }

    $scan = substr($data['scan'], 0, 50000);

    // Keep only the latest 100: delete oldest if at limit
    $files = glob($cacheDir . '/*.json');
    if (count($files) >= 100) {
        usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
        @unlink($files[0]);
    }

    // Generate random 6-char code
    $code = substr(bin2hex(random_bytes(3)), 0, 6);
    $file = $cacheDir . '/' . $code . '.json';

    file_put_contents($file, json_encode([
        'scan' => $scan,
        'time' => time()
    ], JSON_UNESCAPED_UNICODE));

    echo json_encode(['code' => $code]);
    exit;
}

// Load scan data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['code'])) {
    $code = preg_replace('/[^a-f0-9]/', '', $_GET['code']);
    if (strlen($code) !== 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid code']);
        exit;
    }
    $file = $cacheDir . '/' . $code . '.json';

    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    $data = json_decode(file_get_contents($file), true);
    echo json_encode(['scan' => $data['scan']]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
