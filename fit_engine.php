<?php
// EVE Fitting Engine - generate best fit for a ship given a goal (all skills V)
// Input:  GET ship=<type_id>&goal=pvp_dps|pvp_tank|pve|logistics
// Output: fit with per-slot items + computed DPS/EHP/speed/capacitor
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dbFile = __DIR__ . '/eve.db';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$shipId = intval($_GET['ship'] ?? 0);
$goal = $_GET['goal'] ?? 'pvp_dps';
if (!in_array($goal, ['pvp_dps','pvp_tank','pve','logistics'])) $goal = 'pvp_dps';

if (!$shipId) { echo json_encode(['error' => 'missing ship']); exit; }

// ---- ship ----
$st = $pdo->prepare('SELECT * FROM ships WHERE type_id=?');
$st->execute([$shipId]);
$ship = $st->fetch(PDO::FETCH_ASSOC);
if (!$ship) { echo json_encode(['error' => 'ship not found']); exit; }

// ---- helpers ----
function getAttrs($pdo, $typeId) {
    $st = $pdo->prepare('SELECT attribute_id, value FROM item_attrs WHERE type_id=?');
    $st->execute([$typeId]);
    $a = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $a[$r['attribute_id']] = $r['value'];
    return $a;
}

function getItems($pdo, $where, $params = [], $limit = null) {
    $sql = "SELECT type_id,name_zh,name_en,group_name,meta_level,cpu,pg,calibration FROM items WHERE $where ORDER BY meta_level DESC";
    if ($limit) $sql .= " LIMIT " . intval($limit);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// getItems but exclude faction/officer/storyline drops
function getStdItems($pdo, $where, $params = [], $limit = null) {
    $rows = getItems($pdo, $where, $params, $limit * 15);
    $out = [];
    foreach ($rows as $it) {
        if (isRareDrop($it['name_zh'], $it['name_en'])) continue;
        $out[] = $it;
        if ($limit && count($out) >= $limit) break;
    }
    return $out;
}

// exclude faction/officer/deadspace drops — recommend standard T1/T2 fits
function isRareDrop($nameZh, $nameEn) {
    $keys = ['改良型', '海军', '舰队', '暗影天蛇', '主天使', '黑暗血袭者', '恐惧古斯塔斯',
             '真萨沙', '萨沙', '血袭者', '古斯塔斯', '天蛇集团', '天使集团', '莫德团', '官员',
             '限量型', '有标示的', '紧凑型', '原型', '统合部', '金砖', '沙炎', '民团',
             '入侵者', '蛇眼', '毒蛇', '噩梦', '图克尔', '图克',
             'Thukker',
             '帝国海军', '联邦海军', '共和舰队', '共和国', '帝国', '联邦', '天蛇', '天使',
             'Modified', 'Navy', 'Federation', 'Republic', 'Imperial', 'Officer',
             'Serpentis', 'Blood Raider', 'Guristas', 'Sansha', 'Domination',
             'True', 'Dread Guristas', 'Shadow Serpentis', 'Dark Blood', 'Concord',
             'Limited', 'Patterned', 'Compact', 'Prototype', 'Experimental',
             'Pith', 'Gist', 'Caldari Navy', 'A-type', 'B-type', 'C-type', 'X-type', 'Y-type', 'Z-type'];
    foreach ($keys as $k) {
        if (strpos($nameZh, $k) !== false) return true;
        if (strpos($nameEn, $k) !== false) return true;
    }
    return false;
}

// relative weapon power: damageMultiplier / rateOfFire (sec)
function weaponPower($pdo, $typeId) {
    $a = getAttrs($pdo, $typeId);
    $dmgMult = $a[64] ?? 0;          // weaponDamageMultiplier
    $rof = ($a[51] ?? 1000) / 1000;  // rateOfFire ms -> sec
    if ($rof <= 0) return 0;
    return $dmgMult / $rof;
}

// estimate weapon size from name/group (ESI groups have no size prefix)
function weaponSizeOf($name, $group) {
    $n = strtolower($name);
    $g = strtolower($group);
    // missiles by launcher type
    if (strpos($g, 'launcher') !== false) {
        if (strpos($g, 'xl') !== false) return 'XL';
        if (strpos($g, 'light') !== false || strpos($g, 'rocket') !== false || strpos($g, 'defender') !== false) return 'Small';
        if (strpos($g, 'heavy assault') !== false || strpos($g, 'rapid light') !== false) return 'Medium';
        return 'Large'; // heavy, cruise, torpedo
    }
    // explicit size words
    if (strpos($n, 'xl ') !== false || strpos($n, ' xl') !== false || strpos($n, 'super') !== false) return 'XL';
    if (strpos($n, 'small') !== false || strpos($n, 'light') !== false || strpos($n, 'dual light') !== false
        || strpos($n, 'gatling') !== false || strpos($n, 'rocket') !== false) return 'Small';
    if (strpos($n, 'medium') !== false || strpos($n, 'focused medium') !== false || strpos($n, 'heavy assault') !== false) return 'Medium';
    if (strpos($n, 'large') !== false || strpos($n, 'mega') !== false || strpos($n, 'tachyon') !== false
        || strpos($n, 'quad') !== false || strpos($n, 'dual heavy') !== false || strpos($n, 'super') !== false) return 'Large';
    // by caliber (mm)
    if (preg_match('/(\d{2,4})\s*mm/', $n, $m)) {
        $mm = intval($m[1]);
        if ($mm <= 150) return 'Small';
        if ($mm <= 399) return 'Medium';
        return 'Large';
    }
    // by chinese size words
    if (strpos($n, '轻型') !== false || strpos($n, '小型') !== false) return 'Small';
    if (strpos($n, '中型') !== false) return 'Medium';
    if (strpos($n, '重型') !== false || strpos($n, '超级') !== false || strpos($n, '超光速') !== false) return 'Large';
    return 'Medium';
}

// ---- weapon size by ship subcat ----
function weaponSize($subcat) {
    $s = $subcat;
    if (strpos($s, '轻型') !== false || strpos($s, '护卫') !== false || strpos($s, '驱逐') !== false) return 'Small';
    if (strpos($s, '巡洋') !== false || strpos($s, '战列巡洋') !== false) return 'Medium';
    if (strpos($s, '战列舰') !== false || strpos($s, '掠夺') !== false) return 'Large';
    if (strpos($s, '无畏') !== false || strpos($s, '航母') !== false) return 'XL';
    return 'Small';
}

// ---- weapon type by faction (ESI group names) ----
function weaponTypes($cat) {
    $c = $cat;
    if (strpos($c, '艾玛') !== false) return ['Energy Weapon'];
    if (strpos($c, '加达里') !== false) return ['Missile Launcher', 'Hybrid Weapon'];
    if (strpos($c, '盖伦特') !== false) return ['Hybrid Weapon', 'Missile Launcher'];
    if (strpos($c, '米玛塔尔') !== false) return ['Projectile Weapon'];
    if (strpos($c, '三神裔') !== false) return ['Precursor Weapon'];
    return ['Hybrid Weapon', 'Projectile Weapon', 'Missile Launcher', 'Energy Weapon'];
}

// tank preference: shield vs armor
function tankPreference($ship) {
    if (($ship['shield_hp'] ?? 0) > ($ship['armor_hp'] ?? 0)) return 'shield';
    return 'armor';
}

// ---- pick weapon for high slots ----
function pickWeapons($pdo, $ship, $size) {
    $wt = weaponTypes($ship['cat']);
    $nTurrets = intval($ship['turrets'] ?? 0);
    $nLaunchers = intval($ship['launchers'] ?? 0);
    $count = $nTurrets > 0 ? $nTurrets : $nLaunchers;
    if ($count <= 0) $count = intval($ship['hi_slots'] ?? 0);

    $sizeKw = strtolower($size);
    // build candidate group LIKEs based on faction weapon types
    $tryGroups = [];
    foreach ($wt as $wtype) {
        $kw = strtolower($wtype);
        if (strpos($kw, 'missile') !== false) {
            if ($nLaunchers > 0) $tryGroups[] = "%launcher%";
        } else {
            if ($nTurrets > 0) $tryGroups[] = "%" . str_replace(' ', '%', $kw) . "%";
        }
    }
    if ($nLaunchers > 0 && !$tryGroups) $tryGroups[] = "%launcher%";
    if ($nTurrets > 0 && !$tryGroups) $tryGroups[] = "%weapon%";
    if (!$tryGroups) $tryGroups[] = "%weapon%";

    // exclude officer/rare/faction drops — recommend standard T1/T2 fits
    $best = null; $bestPower = -1;
    foreach ($tryGroups as $like) {
        $items = getItems($pdo, "category='weapon' AND meta_level BETWEEN 0 AND 8 AND group_name LIKE ?", [$like], 100);
        foreach ($items as $it) {
            if (isRareDrop($it['name_zh'], $it['name_en'])) continue;
            $ws = weaponSizeOf($it['name_en'], $it['group_name']);
            if ($ws !== $size) continue;   // size must match ship
            $p = weaponPower($pdo, $it['type_id']);
            if ($p > $bestPower) { $bestPower = $p; $best = $it; }
        }
    }

    $chosen = [];
    if ($best) {
        $chosen[] = ['item' => $best, 'dps' => $bestPower, 'count' => $count];
    }
    return $chosen;
}

// ---- pick drones ----
function pickDrones($pdo, $ship) {
    if (($ship['drone_bw'] ?? 0) <= 0) return null;
    // exclude fighter support units (carrier-only), prefer combat drones
    $items = getItems($pdo, "category='drone' AND meta_level BETWEEN 0 AND 8 AND group_name NOT LIKE '%fighter support%'", [], 60);
    $best = null; $bestDps = -1;
    foreach ($items as $it) {
        if (isRareDrop($it['name_zh'], $it['name_en'])) continue;
        $dps = weaponPower($pdo, $it['type_id']);
        if ($dps > $bestDps) { $bestDps = $dps; $best = $it; }
    }
    if (!$best) return null;
    $bandwidth = floatval($ship['drone_bw']);
    $droneBw = 25; // most combat drones use 25 Mbit/s
    $count = intval($bandwidth / $droneBw);
    if ($count < 1) $count = 1;
    return ['item' => $best, 'dps' => $bestDps, 'count' => min($count, 5)];
}

// ---- pick mids/lows/rigs by goal ----
function pickMidsAndLows($pdo, $ship, $goal, $tank) {
    $med = intval($ship['med_slots'] ?? 0);
    $low = intval($ship['low_slots'] ?? 0);
    $mids = [];
    $lows = [];
    $usedCap = 0; // rough capacitor drain (weapons handled separately)

    if ($goal === 'logistics') {
        // repair modules
        $repair = getStdItems($pdo, "category='armor_repair' AND meta_level BETWEEN 0 AND 8", [], 5);
        $shieldRepair = getStdItems($pdo, "category='shield_repair' AND meta_level BETWEEN 0 AND 8", [], 5);
        if ($tank === 'shield' && $shieldRepair) $lows[] = $shieldRepair[0];
        else if ($repair) $lows[] = $repair[0];
        if ($med > 0) {
            $cap = getStdItems($pdo, "category='capacitor' AND meta_level BETWEEN 0 AND 8", [], 3);
            if ($cap) $mids[] = $cap[0];
        }
    } else {
        if ($tank === 'shield') {
            // shield tank: mids resistance, lows repair
            $resist = getStdItems($pdo, "category='resistance' AND group_name LIKE '%shield hardener%' AND meta_level BETWEEN 0 AND 8", [], 10);
            for ($i = 0; $i < $med && $i < count($resist); $i++) $mids[] = $resist[$i];
            if ($low > 0) {
                $repair = getStdItems($pdo, "category='shield_repair' AND meta_level BETWEEN 0 AND 8", [], 3);
                if ($repair) { $lows[] = $repair[0]; }
                $ext = getStdItems($pdo, "category='buffer' AND group_name LIKE '%shield extender%' AND meta_level BETWEEN 0 AND 8", [], 5);
                for ($i = 1; $i < $low && $i-1 < count($ext); $i++) $lows[] = $ext[$i-1];
            }
        } else {
            // armor tank: lows resistance+repair, mids utility
            $resist = getStdItems($pdo, "category='resistance' AND group_name LIKE '%armor hardener%' AND meta_level BETWEEN 0 AND 8", [], 10);
            for ($i = 0; $i < $low && $i < count($resist); $i++) $lows[] = $resist[$i];
            if (count($lows) < $low) {
                $repair = getStdItems($pdo, "category='armor_repair' AND meta_level BETWEEN 0 AND 8", [], 3);
                if ($repair) $lows[] = $repair[0];
            }
            if ($med > 0) {
                $ew = getStdItems($pdo, "category='ewar' AND meta_level BETWEEN 0 AND 8", [], 3);
                if ($goal === 'pvp_dps' && $ew) { $mids[] = $ew[0]; }
                if (count($mids) < $med) {
                    $cap = getStdItems($pdo, "category='capacitor' AND meta_level BETWEEN 0 AND 8", [], 3);
                    if ($cap) $mids[] = $cap[0];
                }
            }
        }
    }

    return ['mids' => array_slice($mids, 0, $med), 'lows' => array_slice($lows, 0, $low)];
}

function pickRigs($pdo, $goal) {
    $kw = $goal === 'pvp_dps' || $goal === 'pve' ? '%damage%' : '%resist%';
    $items = getStdItems($pdo, "category='rig' AND meta_level=1 AND (group_name LIKE ? OR group_name LIKE ?)", ["$kw", "%shield%%"]);
    // fallback to any rig
    if (!$items) $items = getStdItems($pdo, "category='rig' AND meta_level=1", [], 5);
    return array_slice($items, 0, 3);
}

// ---- main ----
$size = weaponSize($ship['subcat']);
$tank = tankPreference($ship);
$weapons = pickWeapons($pdo, $ship, $size);
$drone = pickDrones($pdo, $ship);
$ml = pickMidsAndLows($pdo, $ship, $goal, $tank);
$rigs = pickRigs($pdo, $goal);

// ---- compute ----
$SKILL_BONUS = 1.5; // all relevant weapon skills V: ~+25% dmg, ~+20% rof, + ship bonus ~ approx
$DPS_BASE = 400;    // empirical multiplier: weaponPower -> DPS (includes ammo base damage)

$turretDps = 0;
if ($weapons) {
    foreach ($weapons as $w) $turretDps += $w['dps'] * $w['count'];
    $turretDps = $turretDps * $DPS_BASE * $SKILL_BONUS;
}
$droneDpsTotal = 0;
if ($drone) $droneDpsTotal = $drone['dps'] * $drone['count'] * $DPS_BASE * $SKILL_BONUS;
$totalDps = $turretDps + $droneDpsTotal;

// EHP estimate: base resist assumption + resistance modules
$shieldHp = floatval($ship['shield_hp'] ?? 0);
$armorHp = floatval($ship['armor_hp'] ?? 0);
$structHp = floatval($ship['structure_hp'] ?? 0);
$nResist = count($ml['mids']) + count($ml['lows']);
$shieldResist = 0.35 + 0.08 * $nResist; // approximate per module
$armorResist = 0.55 + 0.08 * $nResist;
$structResist = 0.33;
$ehp = ($shieldHp / (1 - min($shieldResist, 0.9)))
     + ($armorHp / (1 - min($armorResist, 0.9)))
     + ($structHp / (1 - $structResist));

$maxSpeed = floatval($ship['max_speed'] ?? 0);
$capCap = floatval($ship['cap_cap'] ?? 0) / 1000; // GJ
// rough cap sustain: assume ~6.25 GJ/s base recharge peak ~ 1/4, minus weapon+repair drain
$weaponDrain = count($weapons) > 0 ? ($weapons[0]['count'] ?? 0) * 1.5 : 0;
$repairDrain = $goal === 'pvp_tank' || $goal === 'logistics' ? 8 : 2;
$netCap = max(0.5, (($capCap / 60) * 1.2) - $weaponDrain - $repairDrain);
$capSustain = round($netCap, 1);

// ---- build output fit ----
function itemOut($it) {
    return [
        'name' => $it['name_zh'] ?? $it['name_en'],
        'name_en' => $it['name_en'],
        'group' => $it['group_name'],
        'meta' => $it['meta_level'],
        'cpu' => $it['cpu'], 'pg' => $it['pg'],
        'count' => $it['count'] ?? 1
    ];
}

$high = [];
foreach ($weapons as $w) { $h = itemOut($w['item']); $h['count'] = $w['count']; $high[] = $h; }
$mid = array_map('itemOut', $ml['mids']);
$low = array_map('itemOut', $ml['lows']);
$rigOut = array_map('itemOut', $rigs);
$droneOut = $drone ? array_merge(itemOut($drone['item']), ['count' => $drone['count']]) : null;

// required skills summary (approximate by meta level)
$skills = [];
$hasT2 = false;
foreach (array_merge($high, $mid, $low, $rigOut, $droneOut ? [$droneOut] : []) as $it) {
    if (($it['meta'] ?? 1) >= 2) $hasT2 = true;
}
if ($hasT2) $skills[] = '装备对应 T2 技能（全部 V 级）';
$skills[] = '武器系统技能 V（炮术/导弹）';
$skills[] = '防御技能 V（护盾/装甲）';

echo json_encode([
    'ok' => true,
    'ship' => ['name' => $ship['name_zh'], 'name_en' => $ship['name_en'], 'subcat' => $ship['subcat'], 'tank' => $tank],
    'goal' => $goal,
    'fit' => ['high' => $high, 'mid' => $mid, 'low' => $low, 'rigs' => $rigOut, 'drone' => $droneOut],
    'stats' => [
        'dps' => round($totalDps),
        'turret_dps' => round($turretDps),
        'drone_dps' => round($droneDpsTotal),
        'ehp' => round($ehp),
        'speed' => round($maxSpeed),
        'cap_sustain' => $capSustain
    ],
    'skills' => $skills,
    'note' => '装配基于全技能 V 模拟 + 启发式规则，DPS/EHP 为估算值'
], JSON_UNESCAPED_UNICODE);
