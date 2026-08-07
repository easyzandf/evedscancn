<?php
// EVE D-Scan Fitting DB Setup
// Initialize SQLite database with items, item_attrs, ships tables
header('Content-Type: application/json; charset=utf-8');

$dbFile = __DIR__ . '/eve.db';
$new = !file_exists($dbFile);

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        type_id INTEGER PRIMARY KEY,
        name_en TEXT,
        name_zh TEXT,
        group_id INTEGER,
        group_name TEXT,
        category TEXT,          -- weapon / shield / armor / ew / drone / rig / ...
        slot TEXT,              -- high / mid / low / rig / drone
        meta_level INTEGER,     -- 1=T1 2=T2 5=tech3
        cpu REAL,
        pg REAL,
        calibration REAL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS item_attrs (
        type_id INTEGER,
        attribute_id INTEGER,
        value REAL,
        PRIMARY KEY (type_id, attribute_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ships (
        type_id INTEGER PRIMARY KEY,
        name_en TEXT,
        name_zh TEXT,
        group_id INTEGER,
        subcat TEXT,
        tech TEXT,
        cat TEXT,               -- faction
        role TEXT,
        hi_slots INTEGER,
        med_slots INTEGER,
        low_slots INTEGER,
        turrets INTEGER,
        launchers INTEGER,
        drone_bw REAL,
        drone_cap REAL,
        shield_hp REAL,
        armor_hp REAL,
        structure_hp REAL,
        cap_cap REAL,
        max_speed REAL,
        sig REAL,
        mass REAL,
        volume REAL,
        capacity REAL
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attrs_type ON item_attrs (type_id)");

    echo json_encode(['ok' => true, 'db' => $dbFile, 'created' => $new]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
