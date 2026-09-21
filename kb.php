<?php
// 会战报告 —— zKillboard / ESI 数据代理
//
// 站点只有静态文件 + PHP-FPM，没有常驻进程也没有 cron。这里只做「代理 + 缓存」：
// 拉 killmail 流水、按时间聚成会战、算好双方统计，不存原始 killmail，也不轮询。
//
//   GET ?action=battles&type=..&id=..&days=7   -> 会战摘要列表（不含明细）
//   GET ?action=report&type=..&id=..&bid=..    -> 单场会战的完整明细
//   GET ?action=resolve&q=<名称>               -> {results:[{type,id,name}]}
//   GET ?action=names&ids=..                   -> 批量 ID -> 名（角色/军团等）
//   GET ?action=systems&ids=..                 -> 批量星系 ID -> 官方中文名
//   GET ?action=types&ids=..                   -> 批量 typeID -> 中文名
//
// 上游自己就是 10 分钟量级的缓存，这里对齐，既不浪费请求也没有额外延迟。
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 压缩在 PHP 里做：nginx 全局的 gzip_types 里没有 application/json（静态 js 是压的、
// JSON 不是），而本站 server 块属于共享配置。放这儿还能跟代码一起走版本控制。
// 会战明细上千条 killmail，压完能小一个数量级，对带宽紧张的用户差别很大。
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

// 单条 killmail：参战方（含各自打出的伤害）、受害者的承担伤害、价值明细。
// 不带装配 —— 一场会战要一次装几百条，装配能占掉一半体积而会战报告用不到。
// 注意 victim 没有 alliance_id（zKillboard 不给），只有 corporation_id —— 分边时
// 受害者要靠 corp 判断，参战方才有 alliance_id。
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
                'alli'  => isset($a['alliance_id']) ? (int)$a['alliance_id'] : 0,
                'ship'  => isset($a['ship_type_id']) ? (int)$a['ship_type_id'] : 0,
                'dmg'   => isset($a['damage_done']) ? (int)$a['damage_done'] : 0,
                'final' => !empty($a['final_blow']),
            ];
        }
    }

    $out = [
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
        ],
        'attackers' => $atk,
    ];
    return $out;
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

// ---- 会战识别 ----
// 把 killmail 流水按时间间隔切成一场场会战：相邻两条差得久就断开。
// 这是会战报告的核心 —— 从流水还原出「一场仗」这个单位。

// 本方是谁：查联盟就是那个联盟，查军团就是那个军团。参战方带 alliance_id，
// 受害者不带（zKillboard 不给），所以受害者只能靠 corp 判断。
function kbHomeSide($pdo, $type, $id) {
    global $ESI;
    if ($type === 'corporationID') return ['alliance' => 0, 'corps' => [(int)$id]];

    $key = 'ac:' . (int)$id;   // 联盟的军团列表，很少变，缓存 7 天
    $cached = cacheGet($pdo, $key, KB_NAME_TTL);
    if ($cached !== null && isset($cached['corps'])) {
        return ['alliance' => (int)$id, 'corps' => $cached['corps']];
    }
    $r = httpJson("$ESI/alliances/" . (int)$id . "/corporations/?datasource=tranquility");
    $corps = [];
    if (is_array($r)) {
        foreach ($r as $c) { $c = (int)$c; if ($c > 0) $corps[] = $c; }
        cachePut($pdo, $key, ['corps' => $corps]);
    }
    return ['alliance' => (int)$id, 'corps' => $corps];
}

// 同一条 killmail 可能同时出现在 kills 和 losses 里（本方既打又被打），按 id 去重。
// 入参是扁平的 killmail 数组（各页已经合并过了）。
function kbDedupe($kms) {
    $by = [];
    foreach ($kms as $k) {
        if (!isset($by[$k['id']])) $by[$k['id']] = $k;
    }
    return array_values($by);
}

function kbCluster($kms, $gapSec, $minSize) {
    usort($kms, function ($a, $b) { return strcmp($a['time'], $b['time']); });
    $out = []; $cur = []; $prev = null;
    foreach ($kms as $k) {
        $ts = strtotime($k['time']);
        if ($prev !== null && ($ts - $prev) > $gapSec) {
            if (count($cur) >= $minSize) $out[] = $cur;
            $cur = [];
        }
        $cur[] = $k;
        $prev = $ts;
    }
    if (count($cur) >= $minSize) $out[] = $cur;
    return $out;
}

// 一场会战的双方统计。kind 已经说明是谁丢的船：
// 'kill' = 我方拿到的击杀（对面丢船），'loss' = 我方丢船。
function kbBattleSummary($kms, $home) {
    $isHomeCorp = function ($corp) use ($home) {
        return $corp && in_array((int)$corp, $home['corps'], true);
    };
    $isHomeAttacker = function ($a) use ($home, $isHomeCorp) {
        if ($home['alliance'] && (int)$a['alli'] === (int)$home['alliance']) return true;
        return $isHomeCorp($a['corp']);
    };

    $sides = [
        'home' => ['pilots' => [], 'lost' => 0, 'killed' => 0, 'iskLost' => 0.0,
                   'iskDestroyed' => 0.0, 'ships' => [], 'dmg' => []],
        'foe'  => ['pilots' => [], 'lost' => 0, 'killed' => 0, 'iskLost' => 0.0,
                   'iskDestroyed' => 0.0, 'ships' => [], 'dmg' => []],
    ];
    $systems = [];

    foreach ($kms as $k) {
        $v = $k['victim'];
        $vHome = $isHomeCorp($v['corp']);
        $winSide  = $k['kind'] === 'kill' ? 'home' : 'foe';   // 谁拿到了这个击杀
        $loseSide = $k['kind'] === 'kill' ? 'foe'  : 'home';  // 谁丢了船

        $sides[$loseSide]['lost']++;
        $sides[$loseSide]['iskLost'] += $k['value'];
        $sides[$winSide]['killed']++;
        $sides[$winSide]['iskDestroyed'] += $k['value'];

        // 损失舰船按 typeID 归拢，级别（战列舰/护卫舰…）交给前端用 ships-data 映射
        $st = (int)$v['ship'];
        if ($st) {
            if (!isset($sides[$loseSide]['ships'][$st])) $sides[$loseSide]['ships'][$st] = [0, 0.0];
            $sides[$loseSide]['ships'][$st][0]++;
            $sides[$loseSide]['ships'][$st][1] += $k['value'];
        }

        if ($v['char']) $sides[$loseSide]['pilots'][$v['char']] = 1;

        // 参战方：本方/敌对 + 各自打出的伤害（伤害榜用）
        foreach ($k['attackers'] as $a) {
            if (!$a['char']) continue;                       // NPC 没有角色
            $side = $isHomeAttacker($a) ? 'home' : 'foe';
            $sides[$side]['pilots'][$a['char']] = 1;
            if (!isset($sides[$side]['dmg'][$a['char']])) $sides[$side]['dmg'][$a['char']] = [0, 0];
            $sides[$side]['dmg'][$a['char']][0] += $a['dmg'];
            if ($a['final']) $sides[$side]['dmg'][$a['char']][1]++;
        }

        $systems[(int)$k['system']] = (isset($systems[(int)$k['system']]) ? $systems[(int)$k['system']] : 0) + 1;
    }

    $shape = function ($s) {
        $ships = [];
        foreach ($s['ships'] as $tid => $v) $ships[] = [(int)$tid, $v[0], round($v[1], 1)];
        usort($ships, function ($a, $b) { return $b[2] <=> $a[2]; });   // 按价值降序

        $dmg = [];
        foreach ($s['dmg'] as $cid => $v) $dmg[] = [(int)$cid, $v[0], $v[1]];
        usort($dmg, function ($a, $b) { return $b[1] <=> $a[1]; });

        $tot = $s['iskDestroyed'] + $s['iskLost'];
        return [
            'pilots'        => count($s['pilots']),
            'lost'          => $s['lost'],
            'killed'        => $s['killed'],
            'iskLost'       => round($s['iskLost'], 1),
            'iskDestroyed'  => round($s['iskDestroyed'], 1),
            'efficiency'    => $tot > 0 ? round($s['iskDestroyed'] / $tot * 100, 1) : 0,
            'ships'         => array_slice($ships, 0, 40),
            'topDamage'     => array_slice($dmg, 0, 20),
        ];
    };

    arsort($systems);
    $sysList = [];
    foreach ($systems as $sid => $n) $sysList[] = [(int)$sid, $n];
    $sysList = array_slice($sysList, 0, 12);

    $t0 = strtotime($kms[0]['time']);
    $t1 = strtotime($kms[count($kms) - 1]['time']);

    return [
        'id'         => (int)$kms[0]['id'],
        't0'         => $t0,
        't1'         => $t1,
        'killmails'  => count($kms),
        'iskTotal'   => round(array_sum(array_map(function ($k) { return $k['value']; }, $kms)), 1),
        'systems'    => $sysList,
        'home'       => $shape($sides['home']),
        'foe'        => $shape($sides['foe']),
    ];
}

// ---- 会战列表 ----
if ($action === 'battles') {
    $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
    if (!in_array($type, $KB_TYPES, true)) fail('invalid type');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail('invalid id');

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    if ($days < 1 || $days > 7) $days = 7;
    $gap = isset($_GET['gap']) ? (int)$_GET['gap'] : 20;
    if ($gap < 5 || $gap > 240) $gap = 20;

    $pdo = db($dataDir);
    $key = 'bt:' . $type . ':' . $id . ':' . $days . ':' . $gap;
    $data = cacheGet($pdo, $key, KB_TTL);

    if ($data === null) {
        // 7 天联盟数据约 795 条，一页 200，所以要翻页。并行拉，别串行。
        $secs = $days * 86400;
        $urls = [];
        for ($p = 1; $p <= 3; $p++) {
            $urls["k$p"] = "https://zkillboard.com/api/kills/$type/$id/pastSeconds/$secs/page/$p/";
            $urls["l$p"] = "https://zkillboard.com/api/losses/$type/$id/pastSeconds/$secs/page/$p/";
        }
        $res = httpJsonMulti($urls);
        $okAny = false;
        $kms = [];
        foreach ($res as $k => $list) {
            if (!is_array($list)) continue;
            $okAny = true;
            $kind = ($k[0] === 'k') ? 'kill' : 'loss';
            foreach ($list as $km) {
                if (is_array($km)) $kms[] = kbTrimKill($km, $kind);
            }
        }
        if (!$okAny) fail('upstream unavailable', 502);

        $home = kbHomeSide($pdo, $type, $id);
        $clusters = kbCluster(kbDedupe($kms), $gap * 60, 5);

        $battles = [];
        foreach ($clusters as $c) {
            $battles[] = ['id' => (int)$c[0]['id'], 'kms' => $c];
        }
        $data = ['type' => $type, 'id' => $id, 'days' => $days, 'gap' => $gap,
                 'home' => $home, 'battles' => $battles];
        cachePut($pdo, $key, $data);
        cachePrune($pdo);
    }

    // 列表只要摘要，把 killmail 明细留在缓存里，点开某场再取
    $out = [];
    foreach ($data['battles'] as $b) {
        $s = kbBattleSummary($b['kms'], $data['home']);
        $out[] = $s;
    }
    usort($out, function ($a, $b) { return $b['t0'] - $a['t0']; });   // 新的在前
    echo json_encode([
        'type' => $data['type'], 'id' => $data['id'], 'days' => $data['days'],
        'home' => $data['home'], 'battles' => $out, 'cached' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 单场会战明细 ----
if ($action === 'report') {
    $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
    if (!in_array($type, $KB_TYPES, true)) fail('invalid type');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) fail('invalid id');
    $bid = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
    if ($bid <= 0) fail('invalid bid');

    $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
    if ($days < 1 || $days > 7) $days = 7;
    $gap = isset($_GET['gap']) ? (int)$_GET['gap'] : 20;
    if ($gap < 5 || $gap > 240) $gap = 20;

    $pdo = db($dataDir);
    $data = cacheGet($pdo, 'bt:' . $type . ':' . $id . ':' . $days . ':' . $gap, KB_TTL);
    if ($data === null) fail('battle expired, reload the list', 409);

    foreach ($data['battles'] as $b) {
        if ((int)$b['id'] !== $bid) continue;
        echo json_encode([
            'battle' => kbBattleSummary($b['kms'], $data['home']),
            'home'   => $data['home'],
            'kms'    => $b['kms'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    fail('battle not found', 404);
}

// ---- 批量军团 ID -> 名字 / 缩写 / 所属联盟 ----
// 会战名单要显示「联盟」列，但 victim 没有 alliance_id（zKillboard 不给），只有
// corporation_id —— 所以只能拿军团反查。顺便把名字和缩写一起带回来，省一次请求。
// 注意：有军团不属于任何联盟（alliance_id 缺省），那种就显示军团自己。
if ($action === 'corps') {
    $raw = isset($_GET['ids']) ? (string)$_GET['ids'] : '';
    $want = [];
    foreach (explode(',', $raw) as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 80) break;
    }
    if (!$want) fail('invalid ids');

    $pdo = db($dataDir);
    $out = [];
    $miss = [];
    foreach (array_keys($want) as $x) {
        $c = cacheGet($pdo, 'cp:' . $x, KB_NAME_TTL);
        if ($c !== null && isset($c['name'])) $out[$x] = $c;
        else $miss[] = $x;
    }
    if ($miss) {
        $urls = [];
        foreach ($miss as $x) $urls[(string)$x] = "$ESI/corporations/$x/?datasource=tranquility";
        foreach (httpJsonMulti($urls) as $x => $d) {
            if (!is_array($d) || empty($d['name'])) continue;
            $rec = [
                'name'    => (string)$d['name'],
                'ticker'  => isset($d['ticker']) ? (string)$d['ticker'] : '',
                'alliance'=> isset($d['alliance_id']) ? (int)$d['alliance_id'] : 0,
            ];
            $out[(int)$x] = $rec;
            cachePut($pdo, 'cp:' . (int)$x, $rec);
        }
        cachePrune($pdo);
    }
    echo json_encode(['corps' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量星系 ID -> 中文名 ----
// 星系名不能走 /universe/names/：那个接口没有 language 参数，只给英文（Alparena）。
// 而星系有官方中文译名（阿尔帕伦纳），所以单独走 /universe/systems/?language=zh。
// 角色/军团名是玩家自取的，本来就没有中文，仍然走 names。
if ($action === 'systems') {
    $raw = isset($_GET['ids']) ? (string)$_GET['ids'] : '';
    $want = [];
    foreach (explode(',', $raw) as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 60) break;
    }
    if (!$want) fail('invalid ids');

    $pdo = db($dataDir);
    $out = [];
    $miss = [];
    foreach (array_keys($want) as $x) {
        $c = cacheGet($pdo, 'sy:' . $x, KB_TYPE_TTL);
        if ($c !== null && isset($c['name'])) $out[$x] = $c['name'];
        else $miss[] = $x;
    }
    if ($miss) {
        $urls = [];
        foreach ($miss as $x) $urls[(string)$x] = "$ESI/universe/systems/$x/?datasource=tranquility&language=zh";
        foreach (httpJsonMulti($urls) as $x => $d) {
            if (!is_array($d) || empty($d['name'])) continue;
            $out[(int)$x] = (string)$d['name'];
            cachePut($pdo, 'sy:' . (int)$x, ['name' => (string)$d['name']]);
        }
        cachePrune($pdo);
    }
    echo json_encode(['systems' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

fail('invalid action');
