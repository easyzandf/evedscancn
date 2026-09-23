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

// 会战列表的缓存策略：软阈值 + 硬过期
//   超过 KB_SWR_AFTER → 先把旧数据返回给用户，响应发出后**在后台刷新**（用户不用等）
//   超过 KB_TTL       → 缓存彻底不用了，下次请求只能等重新拉
// 这样「新鲜度」和「响应速度」就解耦了：数据旧不代表你要等。
define('KB_TTL', 1800);         // 硬过期 30 分钟
define('KB_SWR_AFTER', 120);    // 软阈值 2 分钟后再有访问就后台刷新
define('KB_NAME_TTL', 604800);  // 实体名几乎不变，缓存 7 天
define('KB_TYPE_TTL', 2592000); // 物品/舰船类型基本不动，缓存 30 天
define('KB_MAX_AGE', 604800);   // 超过 7 天没被碰过的行清掉
// 兜底上限。名字缓存一行才几十字节，一页击杀列表就要解析几百个角色/军团/星系 ID，
// 所以别设太小，否则刚存进去就被淘汰、下次又得重新问 ESI。
define('KB_MAX_ROWS', 5000);

// /universe/names/ 的「二分补救」预算（见 kbNamesSalvage）。三个上限都是必需项：
//   请求数   —— 300 个 ID 里混了 1 个无效的，大约要 2×log2(300)≈18 次才能定位到它；
//               给 14 次够捞回绝大多数，剩下的放弃也不影响已经拿到的那部分。
//   墙钟     —— PHP max_execution_time 是 30s，而上游单次最多要 12s，不设会超时 500。
//   错误配额 —— ESI 的 X-Esi-Error-Limit-Remain 是 100 次/60s，404 也算错；超了它会按
//               出口 IP 封禁。本站只有一个出口，打爆就等于掐死所有用户，所以留够余量。
define('KB_SALVAGE_MAX_REQ', 14);
define('KB_SALVAGE_BUDGET', 8.0);
define('KB_ESI_ERR_FLOOR', 25);

$ESI = 'https://esi.evetech.net/latest';
// zKillboard 要求带可识别的 UA（写明用途和站点），别用默认的 PHP UA
$UA = 'EVE-DScan-CN/1.0 (+https://dscan.dpdns.org/)';

// type 会被拼进上游 URL，必须白名单
$KB_TYPES = ['corporationID', 'allianceID', 'characterID'];

// 「本方」是谁 —— 会战报告的「本方 / 敌对」按这个判断，和 30人本 页面的 LOG_HOME_CORPS 对应。
//   PLA Fleet (PLA-F)            -> People's Liberation Alliance
//   Peoples Liberation Army (P.L.A) -> Goonswarm Federation
// 要加友军就往这两个数组里加 ID。前端 index.html 里有一份同样的常量，两处要一起改。
$KB_HOME_ALLIANCES = [99014027, 1354830081];
$KB_HOME_CORPS     = [98764551, 98190062];

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

// $at 可选，命中时回传这条缓存的写入时间 —— 前端要显示「数据缓存于 X 分钟前」
function cacheGet($pdo, $key, $ttl, &$at = null) {
    $st = $pdo->prepare('SELECT fetched_at, payload FROM kb_cache WHERE k = ?');
    $st->execute([KB_VER . ':' . $key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (time() - (int)$r['fetched_at'] > $ttl) return null;
    $d = json_decode($r['payload'], true);
    if (!is_array($d)) return null;
    $at = (int)$r['fetched_at'];
    return $d;
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

// 和 httpJson 一样返回解码后的数组，但额外把 HTTP 状态码和 ESI 的错误配额余量用出参
// 带出来 —— 光看返回值分不清「上游 404，说这批输入里有无效 ID」和「上游挂了」：
// 前者的错误体 {"error":".."} 本身也是合法 JSON，解码出来同样是个数组。
// $status 为 0 表示连响应都没拿到（网络失败/超时）。
function httpJsonPost($url, $data, &$status = null, &$errRemain = null) {
    global $UA;
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 12,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: $UA\r\n",
        'content'       => json_encode($data),
    ]]);
    $d = @file_get_contents($url, false, $ctx);

    // $http_response_header 是 file_get_contents 在当前作用域里填的魔法变量。
    // 跳转链会有多条状态行，循环里后面的覆盖前面的，正是想要的。
    $status    = 0;
    $errRemain = -1;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m))              $status    = (int)$m[1];
            elseif (preg_match('#^X-Esi-Error-Limit-Remain:\s*(\d+)#i', $h, $m)) $errRemain = (int)$m[1];
        }
    }

    if ($d === false || $d === '') return null;
    $r = json_decode($d, true);
    return is_array($r) ? $r : null;
}

// /universe/names/ 是「全有或全无」：一批里只要有 1 个无效 ID（删号、解散的军团/联盟），
// 上游就 404 并且一个都不返回 —— 同一批里合法的名字也跟着丢。调用方一批最多 300 个，
// 一份大点的会战报告要切成十几批，所以一个坏 ID 就能让整屏名字变成 #1234567。
//
// 这里二分定位：把一批劈成两半分别重问，没坏 ID 的那半直接收下，有坏 ID 的那半继续劈，
// 劈到单条还 404 就是它本身无效，记进负缓存（下次直接跳过，不再问上游）。
//
// 只在整批 404 时被调用，所以稳态下零成本；上游真出问题（420/5xx/超时）立刻止损，
// 拿不到的部分返回空，不会比不补救更差。
function kbNamesSalvage($pdo, array $ids, array &$out) {
    global $ESI;

    // 整批刚在调用方那边 404 过。别原样再问一遍 —— 同样的请求必然还是 404，
    // 白花一次配额；单条的话它就已经确定无效了。
    if (count($ids) === 1) {
        cachePut($pdo, 'nm:' . $ids[0], ['bad' => 1]);
        return;
    }

    $deadline = microtime(true) + KB_SALVAGE_BUDGET;
    $budget   = KB_SALVAGE_MAX_REQ;
    $half     = intdiv(count($ids), 2);
    $stack    = [array_slice($ids, 0, $half), array_slice($ids, $half)];

    while ($stack && $budget > 0 && microtime(true) < $deadline) {
        $part = array_pop($stack);
        $budget--;

        $st = 0; $remain = -1;
        $r = httpJsonPost("$ESI/universe/names/?datasource=tranquility", $part, $st, $remain);

        if ($st === 200 && is_array($r)) {
            foreach ($r as $e) {
                if (!isset($e['id'], $e['name'])) continue;
                $out[(int)$e['id']] = (string)$e['name'];
                cachePut($pdo, 'nm:' . (int)$e['id'], ['name' => (string)$e['name']]);
            }
            continue;
        }
        if ($st !== 404) break;                              // 上游真出问题，止损
        if ($remain >= 0 && $remain < KB_ESI_ERR_FLOOR) break;   // 错误配额快用完，别再花

        if (count($part) === 1) {                            // 单条也 404 -> 确定无效
            cachePut($pdo, 'nm:' . $part[0], ['bad' => 1]);
            continue;
        }
        $half = intdiv(count($part), 2);
        $stack[] = array_slice($part, 0, $half);
        $stack[] = array_slice($part, $half);
    }
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
        if ($c === null)           $miss[] = $x;   // 没缓存过，得问上游
        elseif (isset($c['name'])) $out[$x] = $c['name'];
        elseif (empty($c['bad']))  $miss[] = $x;   // 老结构，照旧重取
        // $c['bad'] 为真：已经确认上游不认这个 ID，跳过，别再花错误配额
    }
    if ($miss) {
        $st = 0;
        $r  = httpJsonPost("$ESI/universe/names/?datasource=tranquility", $miss, $st);
        if ($st === 404) {
            // 上游的 404 意思是「这批里有无效 ID」，是输入问题不是网关故障，所以不能报 502。
            // 注意判断顺序：404 的错误体 {"error":".."} 解码出来也是个数组，
            // 若先看内容，那段 {"error":..} 里没有 id/name 就会走到「上游没给东西」的岔路上去。
            kbNamesSalvage($pdo, $miss, $out);
        } elseif (is_array($r)) {
            foreach ($r as $e) {
                if (!isset($e['id'], $e['name'])) continue;
                $out[(int)$e['id']] = (string)$e['name'];
                cachePut($pdo, 'nm:' . (int)$e['id'], ['name' => (string)$e['name']]);
            }
        } elseif (!$out) {
            // 一个都没解出来，而且上游连可解析的响应都没给 —— 这回是真的拿不到
            fail('upstream unavailable', 502);
        }
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

// 本方 = 固定名单（KB_HOME_*）+ 这次查询的实体自己。
// 参战方带 alliance_id 可以直接判；受害者不带（zKillboard 不给），只能拿 corp 反查。
function kbHomeSide($pdo, $type, $id) {
    global $ESI, $KB_HOME_ALLIANCES, $KB_HOME_CORPS;

    $alliances = $KB_HOME_ALLIANCES;
    $corps     = $KB_HOME_CORPS;

    if ($type === 'allianceID') {
        $alliances[] = (int)$id;
        $key = 'ac:' . (int)$id;   // 联盟的军团列表，很少变，缓存 7 天
        $cached = cacheGet($pdo, $key, KB_NAME_TTL);
        if ($cached !== null && isset($cached['corps'])) {
            $corps = array_merge($corps, $cached['corps']);
        } else {
            $r = httpJson("$ESI/alliances/" . (int)$id . "/corporations/?datasource=tranquility");
            if (is_array($r)) {
                $got = [];
                foreach ($r as $c) { $c = (int)$c; if ($c > 0) $got[] = $c; }
                cachePut($pdo, $key, ['corps' => $got]);
                $corps = array_merge($corps, $got);
            }
        }
    } else {
        $corps[] = (int)$id;
    }

    return [
        'alliances' => array_values(array_unique(array_map('intval', $alliances))),
        'corps'     => array_values(array_unique(array_map('intval', $corps))),
    ];
}

// 批量取军团的所属联盟 —— 受害者没有 alliance_id，只能靠 corp 反查。
// 命中 cp: 缓存的直接读，缺的并行打 ESI。返回 [corpId => allianceId]（无联盟为 0）。
function kbCorpMap($pdo, $corps) {
    global $ESI;
    $out = [];
    $miss = [];
    foreach (array_unique($corps) as $c) {
        $c = (int)$c;
        if ($c <= 0) continue;
        $rec = cacheGet($pdo, 'cp:' . $c, KB_NAME_TTL);
        if ($rec !== null && isset($rec['name'])) {
            $out[$c] = isset($rec['alliance']) ? (int)$rec['alliance'] : 0;
            continue;
        }
        $miss[$c] = 1;
    }
    if ($miss) {
        $urls = [];
        foreach (array_keys($miss) as $c) $urls[(string)$c] = "$ESI/corporations/$c/?datasource=tranquility";
        foreach (httpJsonMulti($urls) as $c => $d) {
            if (!is_array($d) || empty($d['name'])) continue;
            $rec = [
                'name'     => (string)$d['name'],
                'ticker'   => isset($d['ticker']) ? (string)$d['ticker'] : '',
                'alliance' => isset($d['alliance_id']) ? (int)$d['alliance_id'] : 0,
            ];
            $out[(int)$c] = $rec['alliance'];
            cachePut($pdo, 'cp:' . (int)$c, $rec);
        }
    }
    return $out;
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
function kbBattleSummary($kms, $home, $corpMap) {
    // 参战方带 alliance_id 直接判；受害者没有，先看 corp 在不在本方军团名单，
    // 不在就拿 corp 反查联盟（$corpMap）。反查不到就当敌对。
    $isHome = function ($corp, $alli) use ($home, $corpMap) {
        if ($alli && in_array((int)$alli, $home['alliances'], true)) return true;
        $corp = (int)$corp;
        if (!$corp) return false;
        if (in_array($corp, $home['corps'], true)) return true;
        $a = isset($corpMap[$corp]) ? (int)$corpMap[$corp] : 0;
        return $a > 0 && in_array($a, $home['alliances'], true);
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
        // 按**受害者实际属于哪一方**来分，而不是按 zKillboard 查询时的 kind ——
        // 本方名单扩大后，「我方拿到的击杀」里也可能出现本方的人被打（误伤/友军摩擦），
        // 那要算本方损失而不是击杀。
        $vHome = $isHome($v['corp'], 0);
        $loseSide = $vHome ? 'home' : 'foe';   // 谁丢了船
        $winSide  = $vHome ? 'foe'  : 'home';   // 谁拿到了这个击杀

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
            $side = $isHome($a['corp'], $a['alli']) ? 'home' : 'foe';
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
// 重新拉取整份数据 -> 聚类 -> 写回缓存。上游失败返回 null（调用方决定怎么处理），
// 失败时**绝不写缓存** —— 宁可下次再来，也不能把残缺数据固化下来。
function kbRefreshBattles($pdo, $key, $type, $id, $days, $gap) {
    // 分页拉。一页 200 条，7 天约 900 条，所以两侧各要几页。
    //   · 逐轮翻页，只继续拉还有下一页的那一侧
    //   · 任何一页失败先重试一次，仍失败就整份作废
    //   · 判断「还有下一页」只能看这页空不空，**不能看它是否满 200 条** ——
    //     实测 zKillboard 的 losses 第 1 页只返回 198 条，按「不满 200 就是最后一页」
    //     会直接漏掉后面两页（约 270 条），会战列表凭空少一大截
    $PAGE = 200;
    $MAX_PAGES = 6;                      // 每侧上限 1200 条
    $secs = $days * 86400;
    $kms = [];
    $next = ['kills' => 1, 'losses' => 1];
    while ($next) {
        $urls = [];
        foreach ($next as $side => $p) {
            $urls[substr($side, 0, 1) . $p] = "https://zkillboard.com/api/$side/$type/$id/pastSeconds/$secs/page/$p/";
        }
        $res = httpJsonMulti($urls);

        // 失败的页重试一次
        $retry = [];
        foreach ($res as $k => $v) { if (!is_array($v)) $retry[$k] = $urls[$k]; }
        if ($retry) {
            foreach (httpJsonMulti($retry) as $k => $v) $res[$k] = $v;
        }

        $next = [];
        foreach ($res as $k => $list) {
            if (!is_array($list)) return null;                 // 重试后仍失败 -> 整份作废
            $side = ($k[0] === 'k') ? 'kills' : 'losses';
            $p = (int)substr($k, 1);
            $kind = ($side === 'kills') ? 'kill' : 'loss';
            foreach ($list as $km) {
                if (is_array($km)) $kms[] = kbTrimKill($km, $kind);
            }
            if (count($list) > 0 && $p < $MAX_PAGES) $next[$side] = $p + 1;   // 非空就继续翻
        }
    }

    $home = kbHomeSide($pdo, $type, $id);
    $clusters = kbCluster(kbDedupe($kms), $gap * 60, 5);

    $battles = [];
    foreach ($clusters as $c) {
        $battles[] = ['id' => (int)$c[0]['id'], 'kms' => $c];
    }
    $data = ['type' => $type, 'id' => $id, 'days' => $days, 'gap' => $gap,
             'home' => $home, 'battles' => $battles];

    // ⚠️ 上游会返回 CDN 缓存的旧页面：实测 page1 少一条时，最新 killmail 会整体退回约 3 分钟。
    // 后台刷新每 2 分钟就一次，很容易拿旧页面把新缓存覆盖掉，会战列表就会来回来去地回退。
    // 所以写之前先比一下最新 killmail 的时刻，比现有缓存旧就整份放弃（返回 null）。
    $prevAt = 0;
    $prev = cacheGet($pdo, $key, 31536000, $prevAt);          // 读现有的，不看年龄
    if ($prev && isset($prev['battles'])
        && kbNewestKm($battles) < kbNewestKm($prev['battles'])) {
        return null;
    }

    cachePut($pdo, $key, $data);
    cachePrune($pdo);
    return $data;
}

// 一组会战里最新的那条 killmail 的时刻（用来判断这次拉到的数据是不是比缓存旧）
function kbNewestKm($battles) {
    $t = 0;
    foreach ($battles as $b) {
        foreach ($b['kms'] as $k) {
            $ts = strtotime($k['time']);
            if ($ts > $t) $t = $ts;
        }
    }
    return $t;
}

// 后台刷新的互斥锁：多个人同时打开、数据又都旧了时，只让一个真去拉上游。
// 借用同一张 kb_cache 表，用 key 前缀区分；抢不到锁就跳过（说明别人正在刷）。
function kbTryLock($pdo, $key, $ttl) {
    $lk = KB_VER . ':lock:' . $key;
    $pdo->prepare('DELETE FROM kb_cache WHERE k = ? AND fetched_at < ?')->execute([$lk, time() - $ttl]);
    $ins = $pdo->prepare('INSERT INTO kb_cache (k, fetched_at, payload) VALUES (?, ?, ?)
        ON CONFLICT(k) DO NOTHING');
    $ins->execute([$lk, time(), '1']);
    return $ins->rowCount() > 0;         // 插入成功 = 抢到锁
}
function kbUnlock($pdo, $key) {
    $pdo->prepare('DELETE FROM kb_cache WHERE k = ?')->execute([KB_VER . ':lock:' . $key]);
}

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

    // force=1 跳过缓存读取直接重拉上游（前端「强制刷新」按钮）
    $force = !empty($_GET['force']);
    $cachedAt = 0;
    $data = $force ? null : cacheGet($pdo, $key, KB_TTL, $cachedAt);

    // 缓存够旧但还没硬过期 -> 先把旧的发出去，响应之后在后台刷新
    $stale = ($data !== null) && (time() - $cachedAt > KB_SWR_AFTER);

    if ($data === null) {                    // 冷启动 / 硬过期：只能等
        $fresh = kbRefreshBattles($pdo, $key, $type, $id, $days, $gap);
        if ($fresh !== null) {
            $data = $fresh;
            $cachedAt = time();
        } else {
            // 上游拉失败、或拉回来的比缓存还旧 —— 退回缓存里那份（哪怕已经过期），
            // 比直接报错强；同时标成 stale，让前端稍后自动重试
            $data = cacheGet($pdo, $key, 31536000, $cachedAt);
            if ($data === null) fail('upstream unavailable', 502);
            $stale = true;
        }
    }

    // 受害者没有 alliance_id，先把所有受害者军团一次性解析出来（带缓存），
    // 再逐场算统计 —— 否则每场都要现查一遍同一批军团
    $corps = [];
    foreach ($data['battles'] as $b) {
        foreach ($b['kms'] as $k) { if ($k['victim']['corp']) $corps[] = $k['victim']['corp']; }
    }
    $corpMap = kbCorpMap($pdo, $corps);

    // 列表只要摘要，把 killmail 明细留在缓存里，点开某场再取
    $out = [];
    foreach ($data['battles'] as $b) {
        $out[] = kbBattleSummary($b['kms'], $data['home'], $corpMap);
    }
    usort($out, function ($a, $b) { return $b['t0'] - $a['t0']; });   // 新的在前
    echo json_encode([
        'type' => $data['type'], 'id' => $data['id'], 'days' => $data['days'],
        'home' => $data['home'], 'battles' => $out,
        'cachedAt' => $cachedAt,          // 前端据此显示「数据缓存于 X 分钟前」
        'refreshing' => $stale,           // 前端据此提示后台刷新中、并自动重取
    ], JSON_UNESCAPED_UNICODE);

    // 响应已经发出去了，下面这段用户不用等
    if ($stale && function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        if (kbTryLock($pdo, $key, 300)) {        // 抢到锁才刷，避免并发重复拉上游
            kbRefreshBattles($pdo, $key, $type, $id, $days, $gap);   // 失败就算了，下次访问再说
            kbUnlock($pdo, $key);
        }
    }
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
        $corps = [];
        foreach ($b['kms'] as $k) { if ($k['victim']['corp']) $corps[] = $k['victim']['corp']; }
        echo json_encode([
            'battle' => kbBattleSummary($b['kms'], $data['home'], kbCorpMap($pdo, $corps)),
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
