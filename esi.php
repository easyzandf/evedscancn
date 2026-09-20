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

// POST /esi.php  {"names":["A","B",...]}  -> {"results":{"A":{...}|null, ...}}
//
// 批量版：浏览器发一次请求即可解析几十上百个角色，避免几十个并发小请求把
// PHP-FPM 的进程池占满（那样实际会被串行化，首屏要等一两分钟）。
// 名字->ID 用一次 /universe/ids/；角色详情用 curl_multi 并发拉；军团/联盟按
// ID 去重后再拉（30 人本通常只有几个军团，很便宜）。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $names = (isset($body['names']) && is_array($body['names'])) ? $body['names'] : [];
    $names = array_values(array_unique(array_filter(array_map(function($n) {
        return is_string($n) ? trim($n) : '';
    }, $names), function($n) { return strlen($n) >= 2 && strlen($n) < 64; })));
    $names = array_slice($names, 0, 300);

    $out = [];        // name -> result|null
    $idOf = [];       // name -> character id
    $needIds = [];

    foreach ($names as $n) {
        $f = $cacheDir . '/s_' . md5(strtolower($n)) . '.json';
        if (file_exists($f)) {
            $sd = json_decode(file_get_contents($f), true);
            if (!empty($sd['characters'][0]['id'])) $idOf[$n] = $sd['characters'][0]['id'];
            else $out[$n] = null;
        } else {
            $needIds[] = $n;
        }
    }

    // 一次解析所有还没缓存的名字
    if ($needIds) {
        $search = esiPost("$esiBase/universe/ids/", $needIds, 'sb_' . md5(implode('|', $needIds)), 3600);
        $byName = [];
        foreach ((isset($search['characters']) ? $search['characters'] : []) as $c) {
            $byName[strtolower($c['name'])] = $c['id'];
        }
        foreach ($needIds as $n) {
            $id = isset($byName[strtolower($n)]) ? $byName[strtolower($n)] : null;
            // 顺手写单名缓存，之后单查也命中
            file_put_contents($cacheDir . '/s_' . md5(strtolower($n)) . '.json',
                json_encode(['characters' => $id ? [['id' => $id, 'name' => $n]] : []]));
            if ($id) $idOf[$n] = $id; else $out[$n] = null;
        }
    }

    // 角色详情：命中的走缓存，其余并发拉取
    $charData = [];
    $toFetch = [];
    foreach (array_unique(array_values($idOf)) as $id) {
        $f = $cacheDir . '/c2_' . $id . '.json';
        if (file_exists($f) && (time() - filemtime($f)) < 3600) {
            $charData[$id] = json_decode(file_get_contents($f), true);
        } else {
            $toFetch[] = $id;
        }
    }
    if ($toFetch && function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($toFetch as $id) {
            $ch = curl_init("$esiBase/characters/$id/");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: EVE-DScan-CN/1.0'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$id] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.3);
        } while ($running > 0);
        foreach ($hs as $id => $ch) {
            $r = json_decode(curl_multi_getcontent($ch), true);
            if ($r && !empty($r['name'])) {
                $charData[$id] = $r;
                file_put_contents($cacheDir . '/c2_' . $id . '.json', json_encode($r));
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } elseif ($toFetch) {
        foreach ($toFetch as $id) {
            $r = esiGet("$esiBase/characters/$id/", "c2_$id", 3600);
            if ($r) $charData[$id] = $r;
        }
    }

    // 军团 / 联盟（去重后逐个，走已有缓存）
    $orgs = [];
    $orgIds = [];
    foreach ($charData as $c) {
        if (!empty($c['corporation_id'])) $orgIds['cr_' . $c['corporation_id']] = ['cr', $c['corporation_id']];
        if (!empty($c['alliance_id'])) $orgIds['al_' . $c['alliance_id']] = ['al', $c['alliance_id']];
    }
    foreach ($orgIds as $key => $pair) {
        $orgs[$key] = esiGet($pair[0] === 'cr' ? "$esiBase/corporations/{$pair[1]}/" : "$esiBase/alliances/{$pair[1]}/",
                            $key, 86400);
    }

    foreach ($names as $n) {
        if (array_key_exists($n, $out)) continue;
        $id = isset($idOf[$n]) ? $idOf[$n] : null;
        $c = $id && isset($charData[$id]) ? $charData[$id] : null;
        if (!$c) { $out[$n] = null; continue; }
        $corp = !empty($c['corporation_id']) ? $orgs['cr_' . $c['corporation_id']] : null;
        $alliance = !empty($c['alliance_id']) ? $orgs['al_' . $c['alliance_id']] : null;
        $out[$n] = [
            'name'            => isset($c['name']) ? $c['name'] : $n,
            'title'           => isset($c['title']) ? $c['title'] : '',
            'corp_id'         => isset($c['corporation_id']) ? $c['corporation_id'] : 0,
            'corp_name'       => isset($corp['name']) ? $corp['name'] : '',
            'corp_ticker'     => isset($corp['ticker']) ? $corp['ticker'] : '',
            'alliance_id'     => isset($c['alliance_id']) ? $c['alliance_id'] : 0,
            'alliance_name'   => isset($alliance['name']) ? $alliance['name'] : '',
            'alliance_ticker' => isset($alliance['ticker']) ? $alliance['ticker'] : '',
        ];
    }

    echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
    exit;
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
    // cache key bumped to c2_ so entries cached before `title` was passed through get refreshed
    $char = esiGet("$esiBase/characters/$charId/", "c2_$charId", 3600);
    if (!$char) { echo '{}'; exit; }

    $corpId = $char['corporation_id'] ?? 0;
    $allianceId = $char['alliance_id'] ?? 0;

    // Step 3: corp & alliance
    $corp = $corpId ? esiGet("$esiBase/corporations/$corpId/", "cr_$corpId", 86400) : null;
    $alliance = $allianceId ? esiGet("$esiBase/alliances/$allianceId/", "al_$allianceId", 86400) : null;

    echo json_encode([
        'name'            => $char['name'] ?? $name,
        // 军团头衔：公开端点 /characters/{id}/ 就带这个字段，无需 SSO 授权
        'title'           => $char['title'] ?? '',
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
