<?php
// ESI proxy: character name -> corp + alliance info
// Caches all ESI responses to avoid rate limits
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$cacheDir = __DIR__ . '/esi_cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$esiBase = 'https://esi.evetech.net/latest';
$ctx = stream_context_create([
    'http' => [
        'timeout' => 5,
        'header' => "Accept: application/json\r\nUser-Agent: EVE-DScan-CN/1.0\r\n"
    ]
]);

function esiGet($url, $key, $ttl = 86400) {
    global $cacheDir, $ctx;
    $f = $cacheDir . '/' . $key . '.json';
    if (file_exists($f) && (time() - filemtime($f)) < $ttl) {
        return json_decode(file_get_contents($f), true);
    }
    $d = @file_get_contents($url, false, $ctx);
    if (!$d) return null;
    $r = json_decode($d, true);
    if ($r) file_put_contents($f, json_encode($r));
    return $r;
}

function esiPost($url, $data, $key, $ttl = 86400) {
    global $cacheDir;
    $f = $cacheDir . '/' . $key . '.json';
    if (file_exists($f) && (time() - filemtime($f)) < $ttl) {
        return json_decode(file_get_contents($f), true);
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 5,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: EVE-DScan-CN/1.0\r\n",
            'content' => json_encode($data)
        ]
    ]);
    $d = @file_get_contents($url, false, $ctx);
    if (!$d) return null;
    $r = json_decode($d, true);
    if ($r) file_put_contents($f, json_encode($r));
    return $r;
}

// GET /esi.php?name=CharacterName -> full character + corp + alliance info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['name'])) {
    $name = trim($_GET['name']);
    if (strlen($name) < 2) { echo '{}'; exit; }

    // Step 1: resolve name to ID via /universe/ids/
    $searchKey = 's_' . md5(strtolower($name));
    $search = esiPost("$esiBase/universe/ids/", [$name], $searchKey, 86400);
    if (!$search || empty($search['characters'])) { echo '{}'; exit; }

    $charId = $search['characters'][0]['id'];

    // Step 2: character details
    $char = esiGet("$esiBase/characters/$charId/", "c_$charId", 3600);
    if (!$char) { echo '{}'; exit; }

    $corpId = $char['corporation_id'] ?? 0;
    $allianceId = $char['alliance_id'] ?? 0;

    // Step 3: corp & alliance
    $corp = $corpId ? esiGet("$esiBase/corporations/$corpId/", "cr_$corpId", 86400) : null;
    $alliance = $allianceId ? esiGet("$esiBase/alliances/$allianceId/", "al_$allianceId", 86400) : null;

    echo json_encode([
        'name'            => $char['name'] ?? $name,
        'corp_id'         => $corpId,
        'corp_name'       => $corp['name'] ?? '',
        'corp_ticker'     => $corp['ticker'] ?? '',
        'alliance_id'     => $allianceId,
        'alliance_name'   => $alliance['name'] ?? '',
        'alliance_ticker' => $alliance['ticker'] ?? ''
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
