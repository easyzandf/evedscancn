<?php
// Import ships.json / items.json / item_attrs.json into SQLite eve.db
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/eve.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode=WAL');

$report = [];

// ---- ships ----
if (file_exists(__DIR__ . '/ships.json')) {
    $ships = json_decode(file_get_contents(__DIR__ . '/ships.json'), true);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO ships
        (type_id,name_en,name_zh,subcat,tech,cat,role,hi_slots,med_slots,low_slots,turrets,launchers,drone_bw,drone_cap,shield_hp,armor_hp,structure_hp,cap_cap,max_speed,sig,mass,volume,capacity)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    $n = 0;
    foreach ($ships as $s) {
        if (!$s['type_id']) continue;
        $stmt->execute([
            $s['type_id'], $s['name_en'], $s['name_zh'], $s['subcat'], $s['tech'], $s['cat'], $s['role'],
            $s['hi_slots'], $s['med_slots'], $s['low_slots'], $s['turrets'], $s['launchers'],
            $s['drone_bw'], $s['drone_cap'], $s['shield_hp'], $s['armor_hp'], $s['structure_hp'],
            $s['cap_cap'], $s['max_speed'], $s['sig'], $s['mass'], $s['volume'], $s['capacity']
        ]);
        $n++;
    }
    $pdo->commit();
    $report['ships'] = $n;
    @unlink(__DIR__ . '/ships.json');
}

// ---- items ----
if (file_exists(__DIR__ . '/items.json')) {
    $items = json_decode(file_get_contents(__DIR__ . '/items.json'), true);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO items
        (type_id,name_en,name_zh,group_id,group_name,category,slot,meta_level,cpu,pg,calibration)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $pdo->beginTransaction();
    $n = 0;
    foreach ($items as $it) {
        $cat = classifyItem($it['group_name']);
        $slot = slotOf($it['group_name']);
        $stmt->execute([
            $it['type_id'], $it['name_en'], $it['name_zh'], $it['group_id'], $it['group_name'],
            $cat, $slot, $it['meta_level'], $it['cpu'], $it['pg'], $it['calibration']
        ]);
        $n++;
    }
    $pdo->commit();
    $report['items'] = $n;
    @unlink(__DIR__ . '/items.json');
}

// ---- item_attrs ----
if (file_exists(__DIR__ . '/item_attrs.json')) {
    $attrs = json_decode(file_get_contents(__DIR__ . '/item_attrs.json'), true);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO item_attrs (type_id,attribute_id,value) VALUES (?,?,?)');
    $pdo->beginTransaction();
    $n = 0;
    foreach ($attrs as $a) {
        $stmt->execute([$a['type_id'], $a['attribute_id'], $a['value']]);
        $n++;
    }
    $pdo->commit();
    $report['item_attrs'] = $n;
    @unlink(__DIR__ . '/item_attrs.json');
}

echo json_encode(['ok' => true, 'imported' => $report]);

function classifyItem($group) {
    $g = strtolower($group);
    if (strpos($g, 'weapon disruptor') !== false) return 'ewar';
    if (strpos($g, 'rig') !== false) return 'rig';
    if (strpos($g, 'weapon') !== false || strpos($g, 'launcher') !== false) return 'weapon';
    if (strpos($g, 'shield booster') !== false) return 'shield_repair';
    if (strpos($g, 'armor repairer') !== false) return 'armor_repair';
    if (strpos($g, 'hardener') !== false || strpos($g, 'energized') !== false) return 'resistance';
    if (strpos($g, 'extender') !== false || strpos($g, 'plate') !== false) return 'buffer';
    if (strpos($g, 'drone') !== false || strpos($g, 'fighter') !== false) return 'drone';
    if (strpos($g, 'ecm') !== false || strpos($g, 'scrambler') !== false || strpos($g, 'web') !== false
        || strpos($g, 'dampener') !== false || strpos($g, 'painter') !== false || strpos($g, 'disruptor') !== false
        || strpos($g, 'neutralizer') !== false) return 'ewar';
    if (strpos($g, 'afterburner') !== false || strpos($g, 'microwarpdrive') !== false) return 'propulsion';
    if (strpos($g, 'capacitor') !== false) return 'capacitor';
    if (strpos($g, 'nanofiber') !== false || strpos($g, 'damage control') !== false) return 'utility';
    return 'other';
}

function slotOf($group) {
    $g = strtolower($group);
    if (strpos($g, 'turret') !== false || strpos($g, 'launcher') !== false) return 'high';
    if (strpos($g, 'rig') !== false) return 'rig';
    if (strpos($g, 'drone') !== false || strpos($g, 'fighter') !== false) return 'drone';
    if (strpos($g, 'armor') !== false || strpos($g, 'plate') !== false || strpos($g, 'damage control') !== false) return 'low';
    // shields and ewar and propulsion usually mid/low depending; default mid
    if (strpos($g, 'shield') !== false || strpos($g, 'ecm') !== false || strpos($g, 'scrambler') !== false
        || strpos($g, 'web') !== false || strpos($g, 'dampener') !== false || strpos($g, 'painter') !== false
        || strpos($g, 'disruptor') !== false || strpos($g, 'neutralizer') !== false
        || strpos($g, 'afterburner') !== false || strpos($g, 'microwarpdrive') !== false
        || strpos($g, 'capacitor') !== false) return 'mid';
    return 'low';
}
