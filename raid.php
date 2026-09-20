<?php
// 30人本记录 —— 服务器端持久化
//
// 站点是公开的，记录是私人数据，所以用「同步码」当共享密钥：
// 客户端本地生成一个随机码，库里只存 sha256(码)。没有码就读不出任何记录，
// 也无法写入。换设备时在页面上填入同一个码即可取回自己的记录。
//
//   GET  ?action=list&code=XXX           -> {records: [...]}
//   POST {action:"save",   code, record} -> {ok:true}
//   POST {action:"delete", code, id}     -> {ok:true}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dataDir = __DIR__ . '/raid_data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

function fail($msg, $http = 400) {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function codeHash($code) {
    // 同步码只以哈希形式落库
    return hash('sha256', 'eve-raid:' . trim((string)$code));
}

function db($dataDir) {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . $dataDir . '/raid.db');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS runs (
                code       TEXT NOT NULL,
                id         TEXT NOT NULL,
                ts         INTEGER NOT NULL,
                note       TEXT NOT NULL DEFAULT \'\',
                names      TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY (code, id)
            )');
        } catch (Exception $e) {
            fail('database unavailable', 500);
        }
    }
    return $pdo;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---- 读取全部记录 ----
if ($method === 'GET') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    if ($action !== 'list') fail('invalid action');

    $code = isset($_GET['code']) ? $_GET['code'] : '';
    if (strlen(trim($code)) < 8) fail('invalid code');

    $st = db($dataDir)->prepare('SELECT id, ts, note, names, updated_at FROM runs WHERE code = ? ORDER BY ts DESC');
    $st->execute([codeHash($code)]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $names = json_decode($r['names'], true);
        $out[] = [
            'id'        => $r['id'],
            'ts'        => (int)$r['ts'],
            'note'      => $r['note'],
            'names'     => is_array($names) ? $names : [],
            'updatedAt' => (int)$r['updated_at'],
        ];
    }
    echo json_encode(['records' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 写入 ----
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) fail('invalid body');

    $action = isset($body['action']) ? $body['action'] : '';
    $code   = isset($body['code']) ? $body['code'] : '';
    if (strlen(trim($code)) < 8) fail('invalid code');
    $h = codeHash($code);

    if ($action === 'save') {
        $rec = isset($body['record']) && is_array($body['record']) ? $body['record'] : null;
        if (!$rec) fail('missing record');

        $id = isset($rec['id']) ? (string)$rec['id'] : '';
        if ($id === '' || strlen($id) > 64) fail('invalid id');

        $ts   = isset($rec['ts']) ? (int)$rec['ts'] : 0;
        $note = isset($rec['note']) ? mb_substr((string)$rec['note'], 0, 200) : '';
        $upd  = isset($rec['updatedAt']) ? (int)$rec['updatedAt'] : (int)round(microtime(true) * 1000);

        // names 只保留合理的字符串，限制条数与长度
        $names = [];
        if (isset($rec['names']) && is_array($rec['names'])) {
            foreach (array_slice($rec['names'], 0, 300) as $n) {
                if (!is_string($n)) continue;
                $n = mb_substr(trim($n), 0, 64);
                if ($n !== '') $names[] = $n;
            }
        }

        $st = db($dataDir)->prepare(
            'INSERT INTO runs (code, id, ts, note, names, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(code, id) DO UPDATE SET ts = excluded.ts, note = excluded.note,
                                                 names = excluded.names, updated_at = excluded.updated_at'
        );
        $st->execute([$h, $id, $ts, $note, json_encode($names, JSON_UNESCAPED_UNICODE), $upd]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = isset($body['id']) ? (string)$body['id'] : '';
        if ($id === '') fail('invalid id');
        $st = db($dataDir)->prepare('DELETE FROM runs WHERE code = ? AND id = ?');
        $st->execute([$h, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    fail('invalid action');
}

fail('invalid request');
