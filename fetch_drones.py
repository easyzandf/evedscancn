#!/usr/bin/env python3
"""Fetch combat drones (categoryID 18) and merge into items.json."""
import json, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.stdout.reconfigure(encoding='utf-8')

def get(url, t=40):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=t) as r:
        return r.read()

def esi(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=40) as r:
        return json.loads(r.read())

# categoryID 18 = Drone
cat = esi('https://esi.evetech.net/latest/universe/categories/18/')
print('Drone category, groups:', len(cat['groups']))

def get_group(gid):
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        return gid, g['name'], g.get('types', [])
    except Exception:
        return gid, '', []

groups = {}
dronetypes = set()
with ThreadPoolExecutor(max_workers=10) as ex:
    for gid, gname, types in ex.map(get_group, cat['groups']):
        groups[gid] = gname
        dronetypes.update(types)

# select combat drones (exclude mining/salvage/support)
EXCLUDE = ['mining', 'salvage', 'logistic', 'fighter', 'support', 'tracking']
selected = set()
for tid in dronetypes:
    selected.add(tid)
# filter by group name
combat_groups = {}
for gid, gname in groups.items():
    low = gname.lower()
    if any(k in low for k in ['combat', 'damage', 'sentry', 'light', 'medium', 'heavy', 'assault', 'ecm', 'web', 'damp']):
        if not any(k in low for k in EXCLUDE):
            combat_groups[gid] = gname

print('战斗无人机组:')
for gid, n in sorted(combat_groups.items()):
    print('  ', n)

combat_ids = set()
for gid in combat_groups:
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        combat_ids.update(g.get('types', []))
    except Exception:
        pass
print(f'战斗无人机 type 数: {len(combat_ids)}')

def get_item(tid):
    try:
        d = json.loads(get(f'https://ref-data.everef.net/types/{tid}'))
        name_en = d.get('name', {}).get('en', '')
        name_zh = d.get('name', {}).get('zh', '') or name_en
        da = d.get('dogma_attributes') or {}
        if isinstance(da, dict):
            attrs = {int(k): v['value'] for k, v in da.items()}
        else:
            attrs = {int(a['attribute_id']): a['value'] for a in da}
        meta = attrs.get(633, 0)
        return {
            'type_id': tid, 'name_en': name_en, 'name_zh': name_zh,
            'group_id': d.get('group_id'), 'group_name': groups.get(d.get('group_id'), ''),
            'meta_level': int(meta), 'cpu': None, 'pg': None, 'calibration': None,
            'attrs': attrs, 'category': 'drone', 'slot': 'drone'
        }
    except Exception:
        return None

print('拉取无人机数据...')
drone_items = []
with ThreadPoolExecutor(max_workers=12) as ex:
    for res in ex.map(get_item, combat_ids):
        if res and res['name_en']:
            drone_items.append(res)
print(f'拉取: {len(drone_items)} 只')

# merge into existing items.json
existing = json.load(open('items.json', encoding='utf-8'))
existing_ids = {it['type_id'] for it in existing}
added = 0
for di in drone_items:
    if di['type_id'] in existing_ids:
        # update category/slot
        for it in existing:
            if it['type_id'] == di['type_id']:
                it['category'] = 'drone'; it['slot'] = 'drone'
        continue
    existing.append({
        'type_id': di['type_id'], 'name_en': di['name_en'], 'name_zh': di['name_zh'],
        'group_id': di['group_id'], 'group_name': di['group_name'],
        'meta_level': di['meta_level'], 'cpu': None, 'pg': None, 'calibration': None
    })
    added += 1
    existing_ids.add(di['type_id'])
    # attrs
    attrs = json.load(open('item_attrs.json', encoding='utf-8'))
    for aid, val in di['attrs'].items():
        attrs.append({'type_id': di['type_id'], 'attribute_id': aid, 'value': val})
    json.dump(attrs, open('item_attrs.json', 'w', encoding='utf-8'), ensure_ascii=False)

json.dump(existing, open('items.json', 'w', encoding='utf-8'), ensure_ascii=False)
print(f'新增 {added} 只无人机, items 总数: {len(existing)}')
