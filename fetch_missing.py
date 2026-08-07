#!/usr/bin/env python3
"""Fetch missing player ships from ESI, generate records to merge into ships-data."""
import json, re, sys, time, urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.stdout.reconfigure(encoding='utf-8')

with open('C:/Users/zandf/projects/eve-ships-data.json', encoding='utf-8') as f:
    local = json.load(f)
local_ids = set(int(re.search(r'typeID[:：]\s*(\d+)', d['note']).group(1)) for d in local)

def esi(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

# --- get all ship typeIDs from ESI ---
cat = esi('https://esi.evetech.net/latest/universe/categories/6/')
def get_group_types(gid):
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        return gid, g['name'], g.get('types', [])
    except Exception:
        return gid, '?', []
group_info = {}
esi_ids = set()
with ThreadPoolExecutor(max_workers=10) as ex:
    for gid, gname, types in ex.map(get_group_types, cat['groups']):
        group_info[gid] = gname
        esi_ids.update(types)

missing = sorted(esi_ids - local_ids)

# --- blacklist: NPC / test / obsolete non-player ships ---
# Note: ESI 'published' is false for Edition/Navy/特别版 ships even though they exist.
# We keep any player-usable ship; only skip obvious NPC/test/obsolete items.
BLACKLIST_NAME = ['police', 'polaris', 'soct', 'gfx', 'test site', 'cockroach',
                  'immovable', 'npc', 'concord', 'enigma', 'devourer', 'fury',
                  'medusa', 'lynx', 'swordspine', 'blade', 'erinye', 'dagger',
                  'kishar', 'gatherer', 'nation', 'soct ', 'soct1', 'soct2']
BLACKLIST_GROUP = ['Concord', 'Police', 'Polaris', 'SOCT', 'Test Site']

def get_type_info(tid):
    try:
        t = esi(f'https://esi.evetech.net/latest/universe/types/{tid}/')
        return tid, t
    except Exception:
        return tid, None

# also fetch zh name for each
def get_zh_name(tid):
    try:
        t = esi(f'https://esi.evetech.net/latest/universe/types/{tid}/?language=zh')
        return tid, t.get('name', '')
    except Exception:
        return tid, ''

print('Fetching missing ship data from ESI...')
info = {}
with ThreadPoolExecutor(max_workers=15) as ex:
    for tid, t in ex.map(get_type_info, missing):
        if t: info[tid] = t
zh = {}
with ThreadPoolExecutor(max_workers=15) as ex:
    for tid, name in ex.map(get_zh_name, missing):
        zh[tid] = name

# --- build records ---
EN_GROUP_TO_SUBCAT = {
    'Frigate': '护卫舰', 'Assault Frigate': '突击护卫舰', 'Interceptor': '截击舰',
    'Corvette': '轻型护卫舰', 'Shuttle': '穿梭机', 'Logistics Frigate': '后勤护卫舰',
    'Electronic Attack Ship': '电子攻击舰', 'Interdictor': '拦截舰',
    'Covert Ops': '隐形特勤舰', 'Stealth Bomber': '隐形轰炸舰',
    'Tactical Destroyer': '战术驱逐舰', 'Command Destroyer': '指挥驱逐舰',
    'Destroyer': '驱逐舰', 'Expedition Frigate': '勘探护卫舰',
    'Cruiser': '巡洋舰', 'Logistics': '后勤舰', 'Heavy Assault Cruiser': '重型突击巡洋舰',
    'Heavy Interdiction Cruiser': '重型拦截巡洋舰', 'Strategic Cruiser': '战略巡洋舰',
    'Combat Recon': '战斗侦察舰', 'Force Recon': '力场侦察舰',
    'Industrial Command Ship': '工业指挥舰',
    'Battlecruiser': '战地巡洋舰', 'Combat Battlecruiser': '战斗战列巡洋舰',
    'Attack Battlecruiser': '攻击战列巡洋舰',
    'Battleship': '战列舰', 'Black Ops': '黑隐特勤舰', 'Marauder': '掠夺舰',
    'Command Ship': '指挥舰',
    'Dreadnought': '无畏舰', 'Carrier': '航空母舰', 'Force Auxiliary': '战力辅助舰',
    'Supercarrier': '超级航母', 'Titan': '泰坦',
    'Hauler': '运载舰', 'Freighter': '货舰', 'Blockade Runner': '偷运舰',
    'Deep Space Transport': '深层空间运输舰',
    'Mining Barge': '采矿驳船', 'Exhumer': '采掘者', 'Capital Industrial Ship': '旗舰级工业舰',
    'Special Edition Yachts': '特别版游艇', 'Command Carrier': '指挥航母',
    'Lancer Dreadnought': '枪骑兵级无畏舰', 'Strategic Freighter': '战略货舰',
}

def get_tech(t):
    # look for techLevel dogma attribute
    for attr in t.get('dogma_attributes', []):
        if attr['attribute_id'] == 1132:
            lvl = int(attr['value'])
            return {1: 'T1', 2: 'T2', 3: 'T3'}.get(lvl, 'T1')
    return 'T1'

records = []
skipped = []
for tid in missing:
    t = info.get(tid)
    if not t:
        skipped.append((tid, 'no info')); continue
    name_en = t['name']
    name_zh = zh.get(tid, '')
    gname = group_info.get(t['group_id'], '?')
    low = name_en.lower()
    if any(b in low for b in BLACKLIST_NAME) or any(b in gname for b in BLACKLIST_GROUP):
        skipped.append((tid, f'NPC:{name_en}')); continue

    subcat = EN_GROUP_TO_SUBCAT.get(gname, gname)
    # tech / faction classification
    tech = get_tech(t)
    if 'Navy' in name_en or 'Navy' in gname:
        tech = '势力'
    elif 'Edition' in name_en or '特别版' in name_zh or 'YC' in name_en or 'Veteran' in name_en:
        tech = '特别版'
    elif 'Victory' in name_en:
        tech = '特别版'
    elif 'Civilian' in name_en or 'Media' in name_en:
        tech = 'T1'
    elif 'ISC' in name_en or 'Nefantar' in name_en or 'Tash-Murkon' in name_en or 'Kador' in name_en:
        tech = '势力'

    # faction guess from name keywords
    faction = ''
    for fw, fn in [('Amarr', '艾玛'), ('Caldari', '加达里'), ('Gallente', '盖伦特'),
                   ('Minmatar', '米玛塔尔'), ('Blood', '血袭者'), ('Guristas', '古斯塔斯'),
                   ('Serpentis', '天蛇'), ('Thukker', '天使'), ('Mordus', '莫德团'),
                   ('Sarum', '艾玛'), ('Kador', '艾玛'), ('Nugoeihuvi', '加达里'),
                   ('Wiyrkomi', '加达里'), ('Kaalakiota', '加达里'), ('Aliastra', '盖伦特'),
                   ('Inner Zone', '盖伦特'), ('Quafe', '盖伦特'), ('Nefantar', '米玛塔尔'),
                   ('Krusual', '米玛塔尔'), ('Justice', '米玛塔尔'), ('ORE', '联合矿业(ORE)'),
                   ('InterBus', '盖伦特'), ('Intaki', '盖伦特'), ('Sukuuvestaa', '加达里'),
                   ('Vherokior', '米玛塔尔'), ('Ishukone', '加达里')]:
        if fw.lower() in low:
            faction = fn; break
    if not faction:
        faction = gname

    # role guess
    role = ''
    for rw, rn in [('Logistics', '后勤'), ('Mining', '采矿'), ('Freighter', '运输'),
                   ('Blockade', '运输'), ('Transport', '运输'), ('Hauler', '运输'),
                   ('Shuttle', '穿梭'), ('Industrial', '运输'), ('Marauder', '火力'),
                   ('Dreadnought', '旗舰火力'), ('Carrier', '航母'),
                   ('Supercarrier', '超级航母'), ('Titan', '泰坦'),
                   ('Recon', '侦查/隐形'), ('Interceptor', '拦截'), ('Interdictor', '拦截'),
                   ('Stealth', '隐形/轰炸'), ('Covert', '侦查/隐形'), ('Black', '隐形/桥接'),
                   ('ECM', '电子战'), ('Electronic', '电子战'), ('Fleet', '指挥'),
                   ('Command', '指挥'), ('Assault', '火力'), ('Tactical', '战术'),
                   ('Strategic', '战略')]:
        if rw.lower() in low:
            role = rn; break
    if not role and subcat in ('护卫舰', '驱逐舰', '巡洋舰', '战列舰', '战斗战列巡洋舰', '攻击战列巡洋舰'):
        role = '火力'

    # base ship name: strip Edition suffix
    base = re.sub(r'\s+(?:Tash-Murkon|Kador|Nugoeihuvi|Wiyrkomi|Kaalakiota|Aliastra|Inner Zone Shipping|Quafe|Nefantar|Krusual|Justice|Blood Raider|Serpentis|Guristas|Thukker Tribe|Police|Interbus|ORE Development|Victory|YC117)\s*(?:Edition)?', '', name_en).strip()
    base = re.sub(r'\s*Edition$', '', base).strip()

    records.append({
        'cat': faction,
        'subcat': subcat,
        'tech': tech,
        'cn': name_zh,
        'en': name_en,
        'role': role or '火力/通用',
        'note': f'官方组: {gname} / {gname}；typeID: {tid}'
    })

print(f'\n补充 {len(records)} 艘, 跳过 {len(skipped)} 艘:')
for tid, reason in skipped[:20]:
    print(f'  skip {tid}: {reason}')
print(f'... (共跳过 {len(skipped)})')

with open('missing_new.json', 'w', encoding='utf-8') as f:
    json.dump(records, f, ensure_ascii=False, indent=2)

# print summary of what we're adding
from collections import Counter
c = Counter(r['subcat'] for r in records)
print('\n补充分类:')
for k, v in c.most_common():
    print(f'  {k}: {v}')
