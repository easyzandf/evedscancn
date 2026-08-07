#!/usr/bin/env python3
"""Compare local ship data against latest ESI (CCP Static Data)."""
import json, re, sys, time, urllib.request

sys.stdout.reconfigure(encoding='utf-8')

# 1. Load local data
with open('C:/Users/zandf/projects/eve-ships-data.json', encoding='utf-8') as f:
    local = json.load(f)

local_by_id = {}
for d in local:
    m = re.search(r'typeID[:：]\s*(\d+)', d['note'])
    if m:
        local_by_id[int(m.group(1))] = d

local_ids = set(local_by_id.keys())
print(f'本地舰船: {len(local_ids)} 艘')

def esi(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0 (comparison)'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

# 2. Get all ship typeIDs from ESI (categoryID=6 = Ship)
# category -> groups -> types
from concurrent.futures import ThreadPoolExecutor

print('从 ESI 拉取最新船只清单...')
cat = esi('https://esi.evetech.net/latest/universe/categories/6/')
group_ids = cat['groups']
print(f'舰船组数: {len(group_ids)}')

def get_group_types(gid):
    try:
        g = esi(f'https://esi.evetech.net/latest/universe/groups/{gid}/')
        return g.get('types', [])
    except Exception:
        return []

esi_ids = set()
with ThreadPoolExecutor(max_workers=10) as ex:
    for types in ex.map(get_group_types, group_ids):
        esi_ids.update(types)

print(f'ESI 最新舰船总数: {len(esi_ids)}')

# 3. Names lookup for ESI ships (batch /universe/names/, 1000 per call)
# Only fetch names for ships relevant to comparison
ids_to_check = sorted((esi_ids - local_ids) | (local_ids - esi_ids))
print(f'存在差异的船数: {len(ids_to_check)}')

# fetch ESI names for the differing ones in batches of 1000
def get_names(ids):
    if not ids: return {}
    body = json.dumps(ids).encode()
    req = urllib.request.Request(
        'https://esi.evetech.net/latest/universe/names/',
        data=body, headers={'Content-Type': 'application/json', 'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=60) as r:
        res = json.loads(r.read())
    return {x['id']: x['name'] for x in res}

# 4. Compare: ships in ESI but not in local (missing), ships in local but not in ESI (obsolete)
missing = sorted(esi_ids - local_ids)
obsolete = sorted(local_ids - esi_ids)

print(f'\n=== ESI 有但我们本地缺的: {len(missing)} 艘 ===')
for batch_start in range(0, len(missing), 1000):
    batch = missing[batch_start:batch_start+1000]
    names = get_names(batch)
    for i in batch:
        print(f'  {i}\t{names.get(i, "?")}')

print(f'\n=== 我们本地有但 ESI 已没有的(可能是已删除/改名): {len(obsolete)} 艘 ===')
for batch_start in range(0, len(obsolete), 1000):
    batch = obsolete[batch_start:batch_start+1000]
    names = get_names(batch)
    for i in batch:
        print(f'  {i}\t{names.get(i, "?")}\t({local_by_id[i]["en"]})')

print('\n对比完成')
