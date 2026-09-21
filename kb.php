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

// 压缩在 PHP 里做：nginx 全局的 gzip_types 里没有 application/json（静态 js 是压的、
// JSON 不是），而本站 server 块属于共享配置。放这儿还能跟代码一起走版本控制。
// 击杀明细 360KB 压完约 40KB，对带宽紧张的用户差别很大。
// ob_gzhandler 会自己看 Accept-Encoding，客户端不支持就原样输出。
if (!ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    ob_start('ob_gzhandler');
}

$dataDir = __DIR__ . '/kb_data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

// 缓存结构变了就把它 +1：所有键都带这个前缀，旧数据自然失效，
// 不用手动去服务器上删库（曾经因为忘了这事，加了新字段却一直读到旧结构）。
define('KB_VER', 'v2');

define('KB_TTL', 600);          // 聚合数据 10 分钟内直接用缓存
define('KB_NAME_TTL', 604800);  // 实体名几乎不变，缓存 7 天
define('KB_TYPE_TTL', 2592000); // 物品/舰船类型基本不动，缓存 30 天
define('KB_MAX_AGE', 604800);   // 超过 7 天没被碰过的行清掉
// 兜底上限。名字缓存一行才几十字节，一页击杀列表就要解析几百个角色/军团/星系 ID，
// 所以别设太小，否则刚存进去就被淘汰、下次又得重新问 ESI。
define('KB_MAX_ROWS', 5000);

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
    $st->execute([KB_VER . ':' . $key]);
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
    $st->execute([KB_VER . ':' . $key, time(), json_encode($data, JSON_UNESCAPED_UNICODE)]);
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

// 并行抓多个上游。击杀/损失列表一页 200 条、700KB+，串行拉会明显变慢；
// 和 esi.php 一样用 curl_multi，没有 curl 就退回串行。
function httpJsonMulti($urls) {
    global $UA;
    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($urls as $k => $u) $out[$k] = httpJson($u);
        return $out;
    }
    $mh = curl_multi_init();
    $hs = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,   // 实测单页最慢见过 8.5s
            CURLOPT_HTTPHEADER     => ["Accept: application/json", "User-Agent: $UA"],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.3);
    } while ($running > 0);

    $out = [];
    foreach ($hs as $k => $ch) {
        $d = json_decode(curl_multi_getcontent($ch), true);
        $out[$k] = is_array($d) ? $d : null;   // 单边失败不影响另一边
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// 受害者装配。用 [typeID, 数量] 的紧凑数组 —— 200 条 killmail 各二十来件，
// 用对象存体积要大好几倍。
function kbTrimItems($items) {
    if (!is_array($items)) return [];
    $out = [];
    foreach (array_slice($items, 0, 40) as $it) {
        if (!is_array($it) || empty($it['item_type_id'])) continue;
        $q = (int)(isset($it['quantity_dropped']) ? $it['quantity_dropped'] : 0)
           + (int)(isset($it['quantity_destroyed']) ? $it['quantity_destroyed'] : 0);
        $out[] = [(int)$it['item_type_id'], $q > 0 ? $q : 1];
    }
    return $out;
}

// 单条 killmail：参战方（含各自打出的伤害）、受害者的承担伤害与装配、价值明细。
function kbTrimKill($k, $kind) {
    $v   = (isset($k['victim']) && is_array($k['victim'])) ? $k['victim'] : [];
    $zkb = (isset($k['zkb']) && is_array($k['zkb'])) ? $k['zkb'] : [];

    $atk = [];
    if (isset($k['attackers']) && is_array($k['attackers'])) {
        foreach (array_slice($k['attackers'], 0, 50) as $a) {
            if (!is_array($a)) continue;
            $atk[] = [
                'char'  => isset($a['character_id']) ? (int)$a['character_id'] : 0,
                'corp'  => isset($a['corporation_id']) ? (int)$a['corporation_id'] : 0,
                'ship'  => isset($a['ship_type_id']) ? (int)$a['ship_type_id'] : 0,
                'dmg'   => isset($a['damage_done']) ? (int)$a['damage_done'] : 0,
                'final' => !empty($a['final_blow']),
            ];
        }
    }

    return [
        'kind'      => $kind,
        'id'        => isset($k['killmail_id']) ? (int)$k['killmail_id'] : 0,
        'time'      => isset($k['killmail_time']) ? (string)$k['killmail_time'] : '',
        'system'    => isset($k['solar_system_id']) ? (int)$k['solar_system_id'] : 0,
        'value'     => isset($zkb['totalValue']) ? (float)$zkb['totalValue'] : 0.0,
        'dropped'   => isset($zkb['droppedValue']) ? (float)$zkb['droppedValue'] : 0.0,
        'destroyed' => isset($zkb['destroyedValue']) ? (float)$zkb['destroyedValue'] : 0.0,
        'points'    => isset($zkb['points']) ? (int)$zkb['points'] : 0,
        'solo'      => !empty($zkb['solo']),
        'npc'       => !empty($zkb['npc']),
        'hash'      => isset($zkb['hash']) ? (string)$zkb['hash'] : '',
        'victim'    => [
            'char'  => isset($v['character_id']) ? (int)$v['character_id'] : 0,
            'corp'  => isset($v['corporation_id']) ? (int)$v['corporation_id'] : 0,
            'ship'  => isset($v['ship_type_id']) ? (int)$v['ship_type_id'] : 0,
            'dmg'   => isset($v['damage_taken']) ? (int)$v['damage_taken'] : 0,
            'items' => kbTrimItems(isset($v['items']) ? $v['items'] : []),
        ],
        'attackers' => $atk,
    ];
}

// 只留总览卡片要用的字段。原始 payload 71KB，裁完约 1KB —— 公开站点被查几百个
// 实体后，DB 也不会涨到几十 MB。以后要加字段就加在这里，缓存过期后自动重新拉。
function kbTrim($raw, $type, $id) {
    $i = function ($k) use ($raw) { return isset($raw[$k]) ? (int)$raw[$k] : 0; };
    $f = function ($k) use ($raw) { return isset($raw[$k]) ? (float)$raw[$k] : 0.0; };

    // topLists 里本来就带着 Top 舰船 / Top 角色，之前被裁掉了。舰船名要留给前端
    // 用 ships-data.js 换成中文，所以这里只保留 typeID 和数字。
    $topShips = [];
    $topChars = [];
    if (isset($raw['topLists']) && is_array($raw['topLists'])) {
        foreach ($raw['topLists'] as $t) {
            if (!is_array($t) || empty($t['values'])) continue;
            if ($t['type'] === 'shipType') {
                foreach (array_slice($t['values'], 0, 15) as $v) {
                    if (!empty($v['shipTypeID'])) {
                        $topShips[] = ['shipTypeID' => (int)$v['shipTypeID'],
                                       'kills' => (int)(isset($v['kills']) ? $v['kills'] : 0),
                                       'isk'   => (float)(isset($v['isk']) ? $v['isk'] : 0)];
                    }
                }
            } elseif ($t['type'] === 'character') {
                foreach (array_slice($t['values'], 0, 15) as $v) {
                    if (!empty($v['characterID'])) {
                        $topChars[] = ['characterID' => (int)$v['characterID'],
                                       'name' => isset($v['characterName']) ? (string)$v['characterName'] : '',
                                       'kills' => (int)(isset($v['kills']) ? $v['kills'] : 0),
                                       'isk'   => (float)(isset($v['isk']) ? $v['isk'] : 0)];
                    }
                }
            }
        }
    }

    return [
        'topShips' => $topShips,
        'topCharacters' => $topChars,
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

// ---- 击杀 / 损失明细列表 ----
// zKillboard 的列表端点里每条 killmail 都是完整 ESI 结构：attackers[].damage_done、
// victim.damage_taken、装配、最后一击全都有。所以拉一次列表就够前端列表+详情两处用，
// 点开某一条不用再发请求。
if ($action === 'kills') {
    $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
    if (!in_array($type, $KB_TYPES, true)) fail('invalid type');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail('invalid id');

    // zKillboard 的 pastSeconds 上限就是 7 天
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    if ($days < 1 || $days > 7) $days = 7;

    $pdo = db($dataDir);
    $key = 'k:' . $type . ':' . $id . ':' . $days;
    $cached = cacheGet($pdo, $key, KB_TTL);
    if ($cached !== null) {
        $cached['cached'] = true;
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $secs = $days * 86400;
    $res = httpJsonMulti([
        'kills'  => "https://zkillboard.com/api/kills/$type/$id/pastSeconds/$secs/",
        'losses' => "https://zkillboard.com/api/losses/$type/$id/pastSeconds/$secs/",
    ]);
    if ($res['kills'] === null && $res['losses'] === null) fail('upstream unavailable', 502);

    $out = ['type' => $type, 'id' => $id, 'days' => $days, 'kills' => [], 'losses' => []];
    foreach (['kills' => 'kill', 'losses' => 'loss'] as $k => $kind) {
        $list = is_array($res[$k]) ? $res[$k] : [];
        foreach ($list as $km) {
            if (is_array($km)) $out[$k][] = kbTrimKill($km, $kind);
        }
    }

    cachePut($pdo, $key, $out);
    cachePrune($pdo);
    $out['cached'] = false;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量 ID -> 名字 ----
// 角色 / 军团 / 联盟 / 星系都走这一个（ESI 的 /universe/names/ 就是这么设计的）。
// 舰船名前端用本地的 ships-data.js 自己换，不占请求。
if ($action === 'names') {
    $raw = isset($_GET['ids']) ? (string)$_GET['ids'] : '';
    $want = [];
    foreach (explode(',', $raw) as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 300) break;   // 一次别要太多，ESI 那边也吃不下
    }
    if (!$want) fail('invalid ids');
    $want = array_keys($want);

    $pdo = db($dataDir);
    $out = [];
    $miss = [];
    foreach ($want as $x) {
        $c = cacheGet($pdo, 'nm:' . $x, KB_NAME_TTL);
        if ($c !== null && isset($c['name'])) $out[$x] = $c['name'];
        else $miss[] = $x;
    }
    if ($miss) {
        $r = httpJsonPost("$ESI/universe/names/?datasource=tranquility", $miss);
        if (is_array($r)) {
            foreach ($r as $e) {
                if (!isset($e['id'], $e['name'])) continue;
                $out[(int)$e['id']] = (string)$e['name'];
                cachePut($pdo, 'nm:' . (int)$e['id'], ['name' => (string)$e['name']]);
            }
        }
        if ($miss && count($out) < count($want) && !$out) fail('upstream unavailable', 502);
        cachePrune($pdo);
    }
    // 键是数字，JSON 里会变成字符串，前端按字符串取即可
    echo json_encode(['names' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量 typeID -> 中文名 ----
// 前端本地有 ships-data.js（531 艘）+ items-data.js（5976 项），绝大多数船和装备
// 都不用走这里。剩下的是本地词典没有的：NPC 舰船、建筑、无人机之类 —— 那些用
// /universe/names/ 只能拿到英文名，所以这里打 /universe/types/?language=zh 取中文。
if ($action === 'types') {
    $raw = isset($_GET['ids']) ? (string)$_GET['ids'] : '';
    $want = [];
    foreach (explode(',', $raw) as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 60) break;   // 每个都要单独打一次 ESI，别贪多
    }
    if (!$want) fail('invalid ids');

    $pdo = db($dataDir);
    $out = [];
    $miss = [];
    foreach (array_keys($want) as $x) {
        $c = cacheGet($pdo, 'ty:' . $x, KB_TYPE_TTL);
        if ($c !== null && isset($c['name'])) $out[$x] = $c['name'];
        else $miss[] = $x;
    }
    if ($miss) {
        $urls = [];
        foreach ($miss as $x) $urls[(string)$x] = "$ESI/universe/types/$x/?datasource=tranquility&language=zh";
        foreach (httpJsonMulti($urls) as $x => $d) {
            if (!is_array($d) || empty($d['name'])) continue;
            $out[(int)$x] = (string)$d['name'];
            cachePut($pdo, 'ty:' . (int)$x, ['name' => (string)$d['name']]);
        }
        cachePrune($pdo);
    }
    echo json_encode(['types' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

fail('invalid action');
