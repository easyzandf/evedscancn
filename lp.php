<?php
// LP 兑换 —— 国民卫队 LP 商店报价 + 欧服市场行情代理
//
//   GET ?action=offers&corp=1000180[&force=1]        -> LP 商店报价（缓存 24h）
//   GET ?action=aggregate&region=10000002&ids=1,2,3  -> 每个 type 的最高收购价/最低出售价（缓存 15min）
//   GET ?action=book&region=10000002&type_id=123     -> 单物品买卖挂单前五（缓存 15min，按需拉）
//
// 报价来自 ESI /loyalty/stores/{corp}/offers/（公开、免授权）。
// 行情汇总优先用 fuzzwork 聚合（一次可问 200 个 type，省掉几百次请求），
// 失败再退回 ESI 订单簿；前五明细一律走 ESI 订单簿。
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (!ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    ob_start('ob_gzhandler');
}

$dataDir = __DIR__ . '/lp_data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

// 缓存结构改了就把版本号 +1，旧键自然失效
define('LP_VER', 'v1');
define('LP_OFFER_TTL', 86400);   // LP 商店报价：游戏更新才变
define('LP_PRICE_TTL', 900);     // 市场行情：15 分钟
define('LP_STATION_TTL', 2592000); // 空间站名基本不变
define('LP_CONTRACT_TTL', 3600);   // 公开合同索引：每小时重建
define('LP_TYPE_TTL', 2592000);    // 物品体积基本不变，缓存 30 天
define('LP_CONTRACT_URL', 'https://data.everef.net/public-contracts/public-contracts-latest.v2.tar.bz2');
define('LP_MAX_AGE', 1209600);   // 14 天没碰过的缓存行清掉
define('LP_MAX_ROWS', 6000);
define('LP_UA', 'EVE-DScan-CN/1.0 (+https://dscan.dpdns.org/)');

$ESI  = 'https://esi.evetech.net/latest';
$FUZZ = 'https://market.fuzzwork.co.uk/aggregates/';

// 国民卫队 LP 商店：加达里（State Protectorate）/ 盖伦特（Federal Defense Union）
$LP_STORES = [1000180, 1000181];

// 欧服主要贸易中心
$LP_REGIONS = [10000002, 10000043, 10000032, 10000030, 10000042];

function lpFail($msg, $http = 400) {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function lpDb($dir) {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . $dir . '/lp.db');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS lp_cache (
                k          TEXT PRIMARY KEY,
                fetched_at INTEGER NOT NULL,
                payload    TEXT NOT NULL
            )');
        } catch (Exception $e) {
            lpFail('database unavailable', 500);
        }
    }
    return $pdo;
}

function lpCacheGet($pdo, $key, $ttl, &$at = null) {
    $st = $pdo->prepare('SELECT fetched_at, payload FROM lp_cache WHERE k = ?');
    $st->execute([LP_VER . ':' . $key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    if (time() - (int)$r['fetched_at'] > $ttl) return null;
    $d = json_decode($r['payload'], true);
    if (!is_array($d)) return null;
    $at = (int)$r['fetched_at'];
    return $d;
}

function lpCachePut($pdo, $key, $data) {
    $st = $pdo->prepare(
        'INSERT INTO lp_cache (k, fetched_at, payload) VALUES (?, ?, ?)
         ON CONFLICT(k) DO UPDATE SET fetched_at = excluded.fetched_at, payload = excluded.payload'
    );
    $st->execute([LP_VER . ':' . $key, time(), json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function lpCachePrune($pdo) {
    $pdo->prepare('DELETE FROM lp_cache WHERE fetched_at < ?')->execute([time() - LP_MAX_AGE]);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM lp_cache')->fetchColumn();
    if ($n > LP_MAX_ROWS) {
        $pdo->prepare('DELETE FROM lp_cache WHERE k IN (
            SELECT k FROM lp_cache ORDER BY fetched_at ASC LIMIT ?)')->execute([$n - LP_MAX_ROWS]);
    }
}

// 并行 GET。返回 [key => ['data' => decoded|null, 'status' => int, 'headers' => [name => value]]]。
// 头要单独收：ESI 的 X-Pages 决定了订单簿还有没有下一页。
function lpMultiGet(array $urls) {
    if (!function_exists('curl_multi_init')) {
        $out = [];
        foreach ($urls as $k => $u) {
            $out[$k] = ['data' => lpGetOne($u), 'status' => 0, 'headers' => []];
        }
        return $out;
    }
    $mh   = curl_multi_init();
    $hs   = [];
    $hdrs = [];
    foreach ($urls as $k => $u) {
        $hdrs[$k] = [];
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ' . LP_UA],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdrs, $k) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $hdrs[$k][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
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
        $body = curl_multi_getcontent($ch);
        $d    = json_decode($body, true);
        $out[$k] = [
            'data'    => is_array($d) ? $d : null,
            'status'  => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => isset($hdrs[$k]) ? $hdrs[$k] : [],
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// 没有 curl 时的串行兜底
function lpGetOne($url) {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 20,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\nUser-Agent: " . LP_UA . "\r\n",
    ]]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false || $d === '') return null;
    $r = json_decode($d, true);
    return is_array($r) ? $r : null;
}

// ---- LP 商店报价 ----
function lpStoreOffers($pdo, $corp, $force) {
    global $ESI;
    $key = 'of:' . $corp;
    if (!$force) {
        $c = lpCacheGet($pdo, $key, LP_OFFER_TTL, $at);
        if ($c !== null) return [$c, $at];
    }
    $r = lpMultiGet(['o' => "$ESI/loyalty/stores/$corp/offers/?datasource=tranquility"]);
    $d = isset($r['o']['data']) ? $r['o']['data'] : null;
    if (!is_array($d)) return [null, 0];

    $out = [];
    foreach ($d as $o) {
        if (!isset($o['offer_id'], $o['type_id'])) continue;
        $req = [];
        if (isset($o['required_items']) && is_array($o['required_items'])) {
            foreach ($o['required_items'] as $ri) {
                if (isset($ri['type_id'], $ri['quantity'])) {
                    $req[] = ['type_id' => (int)$ri['type_id'], 'quantity' => (int)$ri['quantity']];
                }
            }
        }
        $out[] = [
            'offer_id'       => (int)$o['offer_id'],
            'type_id'        => (int)$o['type_id'],
            'quantity'       => isset($o['quantity']) ? (int)$o['quantity'] : 1,
            'lp_cost'        => isset($o['lp_cost']) ? (int)$o['lp_cost'] : 0,
            'isk_cost'       => isset($o['isk_cost']) ? (float)$o['isk_cost'] : 0.0,
            'ak_cost'        => isset($o['ak_cost']) ? (int)$o['ak_cost'] : 0,
            'required_items' => $req,
        ];
    }
    lpCachePut($pdo, $key, $out);
    lpCachePrune($pdo);
    return [$out, time()];
}

// ---- 订单簿：一次拿一个区域内某个 type 的全部买卖单，按需翻页 ----
function lpOrdersFor($region, array $typeIds) {
    global $ESI;
    $out  = [];
    $urls = [];
    foreach ($typeIds as $t) {
        $urls[(string)$t] = "$ESI/markets/$region/orders/?datasource=tranquility&type_id=$t&page=1";
    }
    if (!$urls) return $out;

    $more = [];
    foreach (lpMultiGet($urls) as $k => $r) {
        if (!is_array($r['data'])) { $out[(int)$k] = null; continue; }
        $out[(int)$k] = $r['data'];
        $pages = isset($r['headers']['x-pages']) ? (int)$r['headers']['x-pages'] : 1;
        $pages = min($pages, 4);   // 单个 type 最多补到 4 页，防病态情况
        for ($p = 2; $p <= $pages; $p++) {
            $more[$k . '|' . $p] = "$ESI/markets/$region/orders/?datasource=tranquility&type_id=$k&page=$p";
        }
    }
    if ($more) {
        foreach (lpMultiGet($more) as $k => $r) {
            if (!is_array($r['data'])) continue;
            $t = (int)strstr($k, '|', true);
            if (isset($out[$t]) && is_array($out[$t])) $out[$t] = array_merge($out[$t], $r['data']);
        }
    }
    return $out;
}

// 订单簿 -> 紧凑结构：买价降序前五、卖价升序前五 + 总量
function lpTrimBook(array $orders) {
    $buy = []; $sell = []; $bv = 0; $sv = 0; $bc = 0; $sc = 0;
    foreach ($orders as $o) {
        $row = [
            'p'   => isset($o['price']) ? (float)$o['price'] : 0.0,
            'v'   => isset($o['volume_remain']) ? (int)$o['volume_remain'] : 0,
            'loc' => isset($o['location_id']) ? (int)$o['location_id'] : 0,
            'min' => isset($o['min_volume']) ? (int)$o['min_volume'] : 0,
        ];
        if (!empty($o['is_buy_order'])) { $buy[] = $row; $bv += $row['v']; $bc++; }
        else                             { $sell[] = $row; $sv += $row['v']; $sc++; }
    }
    usort($buy,  function ($a, $b) { return $b['p'] <=> $a['p']; });
    usort($sell, function ($a, $b) { return $a['p'] <=> $b['p']; });
    return [
        'buy'  => array_slice($buy, 0, 5),
        'sell' => array_slice($sell, 0, 5),
        'bv'   => $bv, 'sv' => $sv, 'bc' => $bc, 'sc' => $sc,
    ];
}

function lpBookSummary(array $book) {
    return [
        'b'  => isset($book['buy'][0]['p'])  ? $book['buy'][0]['p']  : 0.0,
        's'  => isset($book['sell'][0]['p']) ? $book['sell'][0]['p'] : 0.0,
        'bv' => $book['bv'], 'sv' => $book['sv'], 'bc' => $book['bc'], 'sc' => $book['sc'],
    ];
}

// 用 ESI 订单簿给一批 type 落缓存（fuzzwork 挂了时的兜底）
function lpPrimePrices($pdo, $region, array $ids) {
    $out = [];
    foreach (array_chunk($ids, 60) as $chunk) {
        foreach (lpOrdersFor($region, $chunk) as $t => $orders) {
            if ($orders === null) continue;
            $book = lpTrimBook($orders);
            lpCachePut($pdo, 'ag:' . $region . ':' . (int)$t, lpBookSummary($book));
            lpCachePut($pdo, 'bk:' . $region . ':' . (int)$t, $book);
            $out[(int)$t] = lpBookSummary($book);
        }
    }
    return $out;
}

// ---- 行情汇总 ----
function lpAggregate($pdo, $region, array $ids, $force) {
    global $FUZZ;
    $out = []; $miss = []; $at = 0;
    foreach ($ids as $id) {
        $c = $force ? null : lpCacheGet($pdo, 'ag:' . $region . ':' . $id, LP_PRICE_TTL, $t);
        if ($c !== null) { $out[$id] = $c; if ($t > $at) $at = $t; }
        else $miss[] = $id;
    }

    if ($miss) {
        $still = [];
        foreach (array_chunk($miss, 200) as $chunk) {
            $url = $FUZZ . '?region=' . $region . '&types=' . implode(',', $chunk);
            $r = lpMultiGet(['f' => $url]);
            $j = isset($r['f']['data']) ? $r['f']['data'] : null;
            if (!is_array($j)) { $still = array_merge($still, $chunk); continue; }
            foreach ($chunk as $id) {
                $e = isset($j[$id]) ? $j[$id] : (isset($j[(string)$id]) ? $j[(string)$id] : null);
                if (!is_array($e)) { $still[] = $id; continue; }
                $rec = [
                    'b'  => (float)($e['buy']['max'] ?? 0),
                    's'  => (float)($e['sell']['min'] ?? 0),
                    'bv' => (float)($e['buy']['volume'] ?? 0),
                    'sv' => (float)($e['sell']['volume'] ?? 0),
                    'bc' => (int)($e['buy']['orderCount'] ?? 0),
                    'sc' => (int)($e['sell']['orderCount'] ?? 0),
                ];
                lpCachePut($pdo, 'ag:' . $region . ':' . $id, $rec);
                $out[$id] = $rec;
                $at = time();
            }
        }
        // 上游聚合失败的部分，用 ESI 补一点是一点
        if ($still) {
            foreach (lpPrimePrices($pdo, $region, array_slice($still, 0, 120)) as $id => $rec) {
                $out[(int)$id] = $rec;
                $at = time();
            }
        }
        lpCachePrune($pdo);
    }
    if ($at === 0) $at = time();
    return ['prices' => $out, 'at' => $at];
}

// ---- NPC 空间站名（玩家建筑需要授权，拿不到就只显示编号）----
function lpStations($pdo, array $ids) {
    global $ESI;
    $out = []; $miss = [];
    foreach ($ids as $id) {
        $c = lpCacheGet($pdo, 'st:' . $id, LP_STATION_TTL);
        if ($c !== null && isset($c['name'])) $out[$id] = $c['name'];
        else $miss[] = $id;
    }
    if ($miss) {
        $urls = [];
        foreach ($miss as $id) {
            $urls[(string)$id] = "$ESI/universe/stations/$id/?datasource=tranquility&language=zh";
        }
        foreach (lpMultiGet($urls) as $id => $r) {
            if (empty($r['data']['name'])) continue;
            $out[(int)$id] = (string)$r['data']['name'];
            lpCachePut($pdo, 'st:' . (int)$id, ['name' => (string)$r['data']['name']]);
        }
    }
    return $out;
}

// ---- 公开合同：从 everef 快照里抽出 LP 物品的单物品合同最低单价 ----
// 快照每小时更新；PHP 容器没装 bz2 扩展，下载后交给容器自带的 tar 解压。
function lpDownload($url, $dest) {
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . LP_UA],
        ]);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $ok !== false;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 180, 'header' => 'User-Agent: ' . LP_UA]]);
    $d = @file_get_contents($url, false, $ctx);
    if ($d === false) return false;
    return @file_put_contents($dest, $d) !== false;
}

function lpOfferTypeSet($pdo) {
    $set = [];
    foreach ([1000180, 1000181] as $corp) {
        $c = lpCacheGet($pdo, 'of:' . $corp, LP_OFFER_TTL);
        if (!is_array($c)) continue;
        foreach ($c as $o) {
            if (!empty($o['type_id'])) $set[(int)$o['type_id']] = true;
        }
    }
    return $set;
}

function lpBuildContracts($pdo, $dataDir) {
    global $LP_REGIONS;
    $tar = $dataDir . '/everef-contracts.tar.bz2';
    $dir = $dataDir . '/everef';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @chmod($dir, 0777);
    if (!lpDownload(LP_CONTRACT_URL, $tar)) return false;
    @shell_exec('tar -xjf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' contracts.csv contract_items.csv 2>&1');
    $cf = $dir . '/contracts.csv';
    $if = $dir . '/contract_items.csv';
    if (!is_file($cf) || !is_file($if)) return false;

    $allowed = array_flip(array_map('intval', $LP_REGIONS));

    // 1) 合同主表：只要 item_exchange + 白名单区域 + 价格 > 0
    $meta = [];
    $fh = fopen($cf, 'r');
    if (!$fh) return false;
    $head = fgetcsv($fh);
    if (!is_array($head)) { fclose($fh); return false; }
    $ix = array_flip($head);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) !== count($head)) continue;
        if (($r[$ix['type']] ?? '') !== 'item_exchange') continue;
        $rg = (int)($r[$ix['region_id']] ?? 0);
        if (!isset($allowed[$rg])) continue;
        $price = (float)($r[$ix['price']] ?? 0);
        if ($price <= 0) continue;
        $cid = (string)$r[$ix['contract_id']];
        $loc = (int)($r[$ix['station_id']] ?? 0);
        if (!$loc) $loc = (int)($r[$ix['start_location_id']] ?? 0);
        $meta[$cid] = ['rg' => $rg, 'price' => $price, 'loc' => $loc, 'exp' => (string)($r[$ix['date_expired']] ?? '')];
    }
    fclose($fh);

    // 2) 合同物品：只留主表里出现过的合同
    $items = [];
    $fh = fopen($if, 'r');
    if (!$fh) return false;
    $head = fgetcsv($fh);
    if (!is_array($head)) { fclose($fh); return false; }
    $ix = array_flip($head);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) !== count($head)) continue;
        $cid = (string)($r[$ix['contract_id']] ?? '');
        if (!isset($meta[$cid])) continue;
        $tid = (int)($r[$ix['type_id']] ?? 0);
        $qty = (int)($r[$ix['quantity']] ?? 0);
        if ($tid <= 0 || $qty <= 0) continue;
        if (!isset($items[$cid])) $items[$cid] = ['t' => [], 'bpc' => 0, 'runs' => 0, 'me' => 0, 'te' => 0];
        $items[$cid]['t'][$tid] = ($items[$cid]['t'][$tid] ?? 0) + $qty;
        if (($r[$ix['is_blueprint_copy']] ?? '') === 'true') {
            $items[$cid]['bpc'] = 1;
            $items[$cid]['runs'] = (int)($r[$ix['runs']] ?? 0);
            $items[$cid]['me'] = (int)($r[$ix['material_efficiency']] ?? 0);
            $items[$cid]['te'] = (int)($r[$ix['time_efficiency']] ?? 0);
        }
    }
    fclose($fh);

    // 3) 单物品合同：每个区域每个 type 保留最便宜的 3 条
    $regions = [];
    foreach ($LP_REGIONS as $rg) $regions[$rg] = [];
    foreach ($items as $cid => $e) {
        if (count($e['t']) !== 1) continue;
        $tid = (int)array_key_first($e['t']);
        $qty = $e['t'][$tid];
        $m = $meta[$cid];
        $regions[$m['rg']][$tid][] = [
            'unit' => $m['price'] / $qty, 'price' => $m['price'], 'qty' => $qty,
            'bpc' => $e['bpc'], 'runs' => $e['runs'], 'me' => $e['me'], 'te' => $e['te'],
            'loc' => $m['loc'], 'exp' => $m['exp'], 'cid' => (int)$cid,
        ];
    }

    // 4) 只保留 LP 能换到的物品，补上地点名
    $lpTypes = lpOfferTypeSet($pdo);
    foreach ($regions as $rg => $types) {
        $out = []; $locIds = [];
        foreach ($types as $tid => $list) {
            if (!isset($lpTypes[$tid])) continue;
            usort($list, function ($a, $b) { return $a['unit'] <=> $b['unit']; });
            $out[$tid] = array_slice($list, 0, 3);
            foreach ($out[$tid] as $row) {
                if ($row['loc'] > 0 && $row['loc'] < 1000000000000) $locIds[$row['loc']] = true;
            }
        }
        $stations = $locIds ? lpStations($pdo, array_keys($locIds)) : [];
        foreach ($out as $tid => $list) {
            foreach ($list as $i => $row) {
                $out[$tid][$i]['loc_name'] = $row['loc'] >= 1000000000000
                    ? '玩家建筑'
                    : (isset($stations[$row['loc']]) ? $stations[$row['loc']] : '');
            }
        }
        lpCachePut($pdo, 'ct:' . $rg, $out);
    }

    @unlink($cf);
    @unlink($if);
    @unlink($tar);
    lpCachePrune($pdo);
    return true;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$force  = !empty($_GET['force']);

// ---- LP 商店报价 ----
if ($action === 'offers') {
    $corp = isset($_GET['corp']) ? (int)$_GET['corp'] : 0;
    if (!in_array($corp, $LP_STORES, true)) lpFail('unknown store');
    $pdo = lpDb($dataDir);
    list($offers, $at) = lpStoreOffers($pdo, $corp, $force);
    if ($offers === null) lpFail('upstream unavailable', 502);
    echo json_encode(['corp' => $corp, 'offers' => $offers, 'cachedAt' => $at], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量行情汇总（最高收购价 / 最低出售价）----
if ($action === 'aggregate') {
    $region = isset($_GET['region']) ? (int)$_GET['region'] : 0;
    if (!in_array($region, $LP_REGIONS, true)) lpFail('unknown region');

    $want = [];
    foreach (explode(',', isset($_GET['ids']) ? (string)$_GET['ids'] : '') as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 250) break;
    }
    if (!$want) lpFail('invalid ids');

    $pdo = lpDb($dataDir);
    $r = lpAggregate($pdo, $region, array_keys($want), $force);
    $missing = [];
    foreach (array_keys($want) as $id) {
        if (!isset($r['prices'][$id])) $missing[] = $id;
    }
    echo json_encode([
        'region'   => $region,
        'prices'   => $r['prices'],
        'missing'  => $missing,
        'cachedAt' => $r['at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 批量收单深度：只要前五买单，用于「把前五收单全部吃掉」的次数与收益 ----
if ($action === 'depths') {
    $region = isset($_GET['region']) ? (int)$_GET['region'] : 0;
    if (!in_array($region, $LP_REGIONS, true)) lpFail('unknown region');

    $want = [];
    foreach (explode(',', isset($_GET['ids']) ? (string)$_GET['ids'] : '') as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 60) break;
    }
    if (!$want) lpFail('invalid ids');

    $pdo = lpDb($dataDir);
    $out = []; $miss = [];
    foreach (array_keys($want) as $id) {
        $b = $force ? null : lpCacheGet($pdo, 'bk:' . $region . ':' . $id, LP_PRICE_TTL);
        if ($b !== null) $out[$id] = ['buy' => $b['buy'], 'bv' => $b['bv'], 'bc' => $b['bc']];
        else $miss[] = $id;
    }
    if ($miss) {
        lpPrimePrices($pdo, $region, $miss);
        foreach ($miss as $id) {
            $b = lpCacheGet($pdo, 'bk:' . $region . ':' . $id, LP_PRICE_TTL);
            if ($b !== null) $out[$id] = ['buy' => $b['buy'], 'bv' => $b['bv'], 'bc' => $b['bc']];
        }
    }
    echo json_encode(['region' => $region, 'depths' => $out, 'cachedAt' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 公开合同单价（只返回 LP 能换到的物品）----
if ($action === 'contracts') {
    $region = isset($_GET['region']) ? (int)$_GET['region'] : 0;
    if (!in_array($region, $LP_REGIONS, true)) lpFail('unknown region');
    $pdo = lpDb($dataDir);
    $idx = $force ? null : lpCacheGet($pdo, 'ct:' . $region, LP_CONTRACT_TTL);
    if ($idx === null) {
        $fail = lpCacheGet($pdo, 'ct:fail', 300);
        if ($force || $fail === null) {
            if (lpBuildContracts($pdo, $dataDir)) lpCachePut($pdo, 'ct:fail', ['at' => 0]);
            else lpCachePut($pdo, 'ct:fail', ['at' => time()]);
        }
        $idx = lpCacheGet($pdo, 'ct:' . $region, LP_CONTRACT_TTL);
        if ($idx === null) $idx = [];
    }
    echo json_encode(['region' => $region, 'contracts' => $idx, 'cachedAt' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 物品体积（typeID -> m3，缓存 30 天）----
if ($action === 'volumes') {
    $want = [];
    foreach (explode(',', isset($_GET['ids']) ? (string)$_GET['ids'] : '') as $x) {
        $x = (int)trim($x);
        if ($x > 0) $want[$x] = true;
        if (count($want) >= 200) break;
    }
    if (!$want) lpFail('invalid ids');

    $pdo = lpDb($dataDir);
    $out = []; $miss = [];
    foreach (array_keys($want) as $id) {
        $c = lpCacheGet($pdo, 'vol:' . $id, LP_TYPE_TTL);
        if ($c !== null && isset($c['v'])) $out[$id] = (float)$c['v'];
        else $miss[] = $id;
    }
    if ($miss) {
        foreach (array_chunk($miss, 60) as $chunk) {
            $urls = [];
            foreach ($chunk as $id) $urls[(string)$id] = "$ESI/universe/types/$id/?datasource=tranquility";
            foreach (lpMultiGet($urls) as $id => $r) {
                if (!isset($r['data']['volume'])) continue;
                $v = (float)$r['data']['volume'];
                lpCachePut($pdo, 'vol:' . (int)$id, ['v' => $v]);
                $out[(int)$id] = $v;
            }
        }
        lpCachePrune($pdo);
    }
    echo json_encode(['volumes' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 单物品前五挂单（按需拉）----
if ($action === 'book') {
    $region = isset($_GET['region']) ? (int)$_GET['region'] : 0;
    $type   = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
    if (!in_array($region, $LP_REGIONS, true)) lpFail('unknown region');
    if ($type <= 0) lpFail('invalid type_id');

    $pdo = lpDb($dataDir);
    $at  = 0;
    $book = $force ? null : lpCacheGet($pdo, 'bk:' . $region . ':' . $type, LP_PRICE_TTL, $at);
    if ($book === null) {
        $orders = lpOrdersFor($region, [$type]);
        $o = isset($orders[$type]) ? $orders[$type] : [];
        $book = lpTrimBook(is_array($o) ? $o : []);
        lpCachePut($pdo, 'bk:' . $region . ':' . $type, $book);
        lpCachePut($pdo, 'ag:' . $region . ':' . $type, lpBookSummary($book));
        lpCachePrune($pdo);
        $at = time();
    }

    // 只给 NPC 空间站补名字；>=1e12 是玩家建筑，公开接口拿不到名字
    $locIds = [];
    foreach (array_merge($book['buy'], $book['sell']) as $row) {
        $lid = (int)$row['loc'];
        if ($lid > 0 && $lid < 1000000000000) $locIds[$lid] = true;
    }
    $stations = $locIds ? lpStations($pdo, array_keys($locIds)) : [];

    echo json_encode([
        'type_id'  => $type,
        'region'   => $region,
        'buy'      => $book['buy'],
        'sell'     => $book['sell'],
        'bv'       => $book['bv'],
        'sv'       => $book['sv'],
        'bc'       => $book['bc'],
        'sc'       => $book['sc'],
        'stations' => $stations,
        'cachedAt' => $at,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

lpFail('invalid request');
