#!/usr/bin/env python3
"""Fetch curated fitting items (weapons, defense, EW, drones, rigs) from everef ref-data.
Outputs items.json + attrs.json for import into SQLite."""
import json, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.stdout.reconfigure(encoding='utf-8')

def get(url, t=40):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0 (fitting items)'})
    with urllib.request.urlopen(req, timeout=t) as r:
        return r.read()

def esi(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=40) as r:
        return json.loads(r.read())

# ---- determine item groups of interest ----
# categoryID 7 = Module
cat = esi('https://esi.evetech.net/latest/universe/categories/7/')
group_ids = cat['groups']

def get_group(gid):
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        return gid, g['name'], g.get('types', [])
    except Exception:
        return gid, '', []

groups = {}
all_types = set()
with ThreadPoolExecutor(max_workers=10) as ex:
    for gid, gname, types in ex.map(get_group, group_ids):
        groups[gid] = gname
        all_types.update(types)
print(f'Module 总 group: {len(groups)}, 总 type: {len(all_types)}')

# ---- select curated groups by name keywords ----
WEAPON_KW = ['energy weapon', 'projectile weapon', 'hybrid weapon', 'missile launcher',
             'precursor weapon', 'drone bay']
DEFENSE_KW = ['shield booster', 'armor repairer', 'shield hardener', 'armor hardener',
              'shield extender', 'armor plate', 'damage control', 'ancillary']
EW_KW = ['warp scrambler', 'warp disruptor', 'ecm ', 'webifier', 'stasis web',
         'tracking disruptor', 'sensor dampener', 'target painter', 'energy neutralizer']
DRONE_KW = ['combat drone', 'sentr', 'medium drone', 'light drone', 'heavy drone',
            'fighter', 'assault drone']
RIG_KW = ['rig', 'nanofiber', 'afterburner', 'microwarpdrive', 'capacitor recharger',
          'capacitor battery', 'shield power relay', 'armor kinetic']
SKILL_KW = ['mining laser']

ALL_KW = WEAPON_KW + DEFENSE_KW + EW_KW + DRONE_KW + RIG_KW

selected_groups = {}
for gid, gname in groups.items():
    low = gname.lower()
    if any(k in low for k in ALL_KW):
        selected_groups[gid] = gname

print(f'选中 group: {len(selected_groups)}')
for gid, name in sorted(selected_groups.items(), key=lambda x: x[1]):
    print('  ', name)

# collect typeIDs
item_typeids = set()
for gid in selected_groups:
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        item_typeids.update(g.get('types', []))
    except Exception:
        pass
print(f'装备 type 数: {len(item_typeids)}')

# ---- fetch each item ref-data ----
def get_item(tid):
    try:
        d = json.loads(get(f'https://ref-data.everef.net/types/{tid}'))
        name_en = d.get('name', {}).get('en', '')
        name_zh = d.get('name', {}).get('zh', '') or name_en
        da = d.get('dogma_attributes') or {}
        # normalize to list
        if isinstance(da, dict):
            attrs = {int(k): v['value'] for k, v in da.items()}
        else:
            attrs = {int(a['attribute_id']): a['value'] for a in da}
        meta = attrs.get(633, 0)
        return {
            'type_id': tid,
            'name_en': name_en,
            'name_zh': name_zh,
            'group_id': d.get('group_id'),
            'group_name': groups.get(d.get('group_id'), ''),
            'meta_level': int(meta),
            'cpu': attrs.get(50),
            'pg': attrs.get(11),
            'calibration': attrs.get(1153),
            'attrs': attrs
        }
    except Exception:
        return None

print('拉取装备数据...')
items = []
with ThreadPoolExecutor(max_workers=12) as ex:
    for res in ex.map(get_item, item_typeids):
        if res and res['name_en']:
            items.append(res)

print(f'成功拉取: {len(items)} 件')

# ---- build output ----
item_rows = []
attr_rows = []
for it in items:
    item_rows.append({
        'type_id': it['type_id'], 'name_en': it['name_en'], 'name_zh': it['name_zh'],
        'group_id': it['group_id'], 'group_name': it['group_name'],
        'meta_level': it['meta_level'], 'cpu': it['cpu'], 'pg': it['pg'],
        'calibration': it['calibration']
    })
    for aid, val in it['attrs'].items():
        attr_rows.append({'type_id': it['type_id'], 'attribute_id': aid, 'value': val})

with open('items.json', 'w', encoding='utf-8') as f:
    json.dump(item_rows, f, ensure_ascii=False)
with open('item_attrs.json', 'w', encoding='utf-8') as f:
    json.dump(attr_rows, f, ensure_ascii=False)

print(f'items.json: {len(item_rows)} 行, item_attrs.json: {len(attr_rows)} 行')

# sample weapons
weap = [i for i in items if any(k in i['group_name'].lower() for k in ['turret','launcher'])][:8]
print('\n武器样例:')
for w in weap:
    print(f"  {w['name_zh']} [{w['group_name']}] M{w['meta_level']}")
