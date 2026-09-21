<?php
// KB 统计 —— zKillboard 聚合数据代理
//
// 站点只有静态文件 + PHP-FPM，没有常驻进程也没有 cron。好在 zKillboard 的
// 聚合端点 /api/stats/{type}/{id}/kills/ 一次调用就返回总览需要的全部数字，
// 所以这里只做「代理 + 缓存」：不存原始 killmail，也不需要轮询。
//
//   GET ?action=resolve&q=<名称>      -> {results:[{type,id,name}]}
//   GET ?action=stats&type=..&id=..   -> 裁剪后的聚合数据
//
// 上游自己就是 10 分钟缓存，这里对齐，既不浪费请求也没有额外延迟。
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dataDir = __DIR__ . '/kb_data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

define('KB_TTL', 600);          // 聚合数据 10 分钟内直接用缓存
define('KB_NAME_TTL', 604800);  // 实体名几乎不变，缓存 7 天
define('KB_MAX_AGE', 604800);   // 超过 7 天没被碰过的行清掉
define('KB_MAX_ROWS', 500);     // 兜底上限

$ESI = 'https://esi.evetech.net/latest';
// zKillboard 要求带可识别的 UA（写明用途和站点），别用默认的 PHP UA
$UA = 'EVE-DScan-CN/1.0 (+https://dscan.dpdns.org/)';

// type 会被拼进上游 URL，必须白名单
$KB_TYPES = ['corporationID', 'allianceID', 'characterID'];

function fail($msg, $http = 400) {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function db($dataDir) {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . $dataDir . '/kb.db');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS kb_cache (
                k          TEXT PRIMARY KEY,
                fetched_at INTEGER NOT NULL,
                payload    TEXT NOT NULL
            )');
        } catch (Exception $e) {
            fail('database unavailable', 500);
        }
    }
    return $pdo;
}

function cacheGet($pdo, $key, $ttl) {
    $st = $pdo->prepare('SELECT fetched_at, payload FROM kb_cache WHERE k = ?');
    $st->execute([$key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (time() - (int)$r['fetched_at'] > $ttl) return null;
    $d = json_decode($r['payload'], true);
    return is_array($d) ? $d : null;
}

function cachePut($pdo, $key, $data) {
    $st = $pdo->prepare(
        'INSERT INTO kb_cache (k, fetched_at, payload) VALUES (?, ?, ?)
         ON CONFLICT(k) DO UPDATE SET fetched_at = excluded.fetched_at, payload = excluded.payload'
    );
    $st->execute([$key, time(), json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

// 只在冷路径（真的打了上游之后）调用，热路径不碰
function cachePrune($pdo) {
    $pdo->prepare('DELETE FROM kb_cache WHERE fetched_at < ?')->execute([time() - KB_MAX_AGE]);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM kb_cache')->fetchColumn();
    if ($n > KB_MAX_ROWS) {
        $pdo->prepare('DELETE FROM kb_cache WHERE k IN (
            SELECT k FROM kb_cache ORDER BY fetched_at ASC LIMIT ?)')->execute([$n - KB_MAX_ROWS]);
    }
}

// 上游 GET -> 解码后的数组；失败返回 null。
// 故意不发 Accept-Encoding: gzip —— file_get_contents 不会自动解压，发了会拿到二进制。
function httpJson($url) {
    global $UA;
    $ctx = stream_context_create(['http' => [
        'timeout'       => 12,   // zKillboard 偶发要 8s+
        'ignore_errors' => true, // 让非 200 也返回 body，好判断到底是哪一步挂了
        'header'        => "Accept: application/json\r\nUser-Agent: $UA\r\n",
    ]]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false || $d === '') return null;
    $r = json_decode($d, true);
    return is_array($r) ? $r : null;
}

function httpJsonPost($url, $data) {
    global $UA;
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 12,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: $UA\r\n",
        'content'       => json_encode($data),
    ]]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false || $d === '') return null;
    $r = json_decode($d, true);
    return is_array($r) ? $r : null;
}

// 只留总览卡片要用的字段。原始 payload 71KB，裁完约 1KB —— 公开站点被查几百个
// 实体后，DB 也不会涨到几十 MB。以后要加字段就加在这里，缓存过期后自动重新拉。
function kbTrim($raw, $type, $id) {
    $i = function ($k) use ($raw) { return isset($raw[$k]) ? (int)$raw[$k] : 0; };
    $f = function ($k) use ($raw) { return isset($raw[$k]) ? (float)$raw[$k] : 0.0; };
    return [
        'type'                => $type,
        'id'                  => (int)$id,
        'kills'               => $i('shipsDestroyed'),
        'losses'              => $i('shipsLost'),
        'iskDestroyed'        => $f('iskDestroyed'),
        'iskLost'             => $f('iskLost'),
        'pointsDestroyed'     => $i('pointsDestroyed'),
        'pointsLost'          => $i('pointsLost'),
        'soloKills'           => $i('soloKills'),
        'soloLosses'          => $i('soloLosses'),
        'attackersDestroyed'  => $i('attackersDestroyed'),
        'attackersLost'       => $i('attackersLost'),
        'dangerRatio'         => $i('dangerRatio'),
        'gangRatio'           => $i('gangRatio'),
        'avgGangSize'         => $f('avgGangSize'),
        'soloRatio'           => $f('soloRatio'),
        'epoch'               => $i('epoch'),
    ];
}

// ID -> 名字（ESI /universe/names/）。直接粘 ID 查询时也要有个标题。
function kbName($pdo, $type, $id) {
    global $ESI;
    $key = 'n:' . $type . ':' . $id;
    $cached = cacheGet($pdo, $key, KB_NAME_TTL);
    if ($cached !== null) return isset($cached['name']) ? $cached['name'] : null;

    $r = httpJsonPost("$ESI/universe/names/?datasource=tranquility", [$id]);
    $name = null;
    if (is_array($r)) {
        foreach ($r as $e) {
            if (isset($e['id'], $e['name']) && (int)$e['id'] === (int)$id) { $name = (string)$e['name']; break; }
        }
    }
    // 解析不出来就不落库，下次再试，免得把一次失败固化 7 天
    if ($name !== null) cachePut($pdo, $key, ['name' => $name]);
    return $name;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

// ---- 名称 -> 实体 ----
if ($action === 'resolve') {
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q === '' || mb_strlen($q) > 64) fail('invalid query');

    $pdo = db($dataDir);
    $key = 'q:' . md5(strtolower($q));
    $cached = cacheGet($pdo, $key, KB_NAME_TTL);
    if ($cached !== null) {
        echo json_encode(['results' => $cached], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $r = httpJsonPost("$ESI/universe/ids/?datasource=tranquility", [$q]);
    if ($r === null) fail('upstream unavailable', 502);

    // ESI 只认全名，军团 ticker（PLA-F）匹配不到 —— 返回空数组，让前端给出明确提示
    $out = [];
    foreach (['characters' => 'characterID', 'corporations' => 'corporationID', 'alliances' => 'allianceID'] as $k => $type) {
        if (empty($r[$k]) || !is_array($r[$k])) continue;
        foreach ($r[$k] as $e) {
            if (isset($e['id'], $e['name'])) {
                $out[] = ['type' => $type, 'id' => (int)$e['id'], 'name' => (string)$e['name']];
            }
        }
    }
    cachePut($pdo, $key, $out);
    echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 聚合数据 ----
if ($action === 'stats') {
    $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
    if (!in_array($type, $KB_TYPES, true)) fail('invalid type');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail('invalid id');

    $pdo = db($dataDir);
    $key = 's:' . $type . ':' . $id;
    $cached = cacheGet($pdo, $key, KB_TTL);
    if ($cached !== null) {
        $cached['cached'] = true;
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 显式带 /kills/，省掉 zKillboard 那次 302
    $raw = httpJson("https://zkillboard.com/api/stats/$type/$id/kills/");
    if ($raw === null) fail('upstream unavailable', 502);

    $out = kbTrim($raw, $type, $id);
    $out['name'] = kbName($pdo, $type, $id);
    cachePut($pdo, $key, $out);
    cachePrune($pdo);
    $out['cached'] = false;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

fail('invalid action');
