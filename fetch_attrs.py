#!/usr/bin/env python3
"""Fetch base attributes for all ships from ESI, merge into ships-data."""
import json, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.stdout.reconfigure(encoding='utf-8')

def esi(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

# attribute ids we care about
ATTR = {
    11: 'pg', 48: 'cpu', 37: 'maxVel', 552: 'sig', 564: 'scanRes',
    12: 'low', 13: 'med', 14: 'hi', 101: 'launcher', 102: 'turret',
    1271: 'droneBw', 283: 'droneCap', 281: 'inertia',
    263: 'shield', 265: 'armor', 9: 'structure', 479: 'capCap'
}

def get_type(tid):
    try:
        t = esi(f'https://esi.evetech.net/latest/universe/types/{tid}/')
        attrs = {}
        for a in t.get('dogma_attributes', []):
            aid = a['attribute_id']
            if aid in ATTR:
                attrs[ATTR[aid]] = round(float(a['value']), 1) if float(a['value']) != int(float(a['value'])) else int(a['value'])
        return {
            'typeID': tid,
            'mass': t.get('mass'),
            'volume': t.get('volume'),
            'capacity': t.get('capacity'),
            'radius': t.get('radius'),
            'attrs': attrs
        }
    except Exception:
        return None

with open('C:/Users/zandf/projects/eve-ships-data.json', encoding='utf-8') as f:
    data = json.load(f)

# collect typeIDs
typeids = []
for d in data:
    m = re.search(r'typeID[:：]\s*(\d+)', d.get('note',''))
    if m: typeids.append(int(m.group(1)))

print(f'拉取 {len(typeids)} 艘船属性...')
attr_map = {}
with ThreadPoolExecutor(max_workers=15) as ex:
    for res in ex.map(get_type, typeids):
        if res:
            attr_map[res['typeID']] = res

merged = 0
for d in data:
    m = re.search(r'typeID[:：]\s*(\d+)', d.get('note',''))
    if not m: continue
    tid = int(m.group(1))
    if tid in attr_map:
        d['attr'] = attr_map[tid]
        merged += 1

print(f'成功附加属性: {merged} 艘')

with open('C:/Users/zandf/projects/eve-ships-data.json', 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

js = '// EVE Ship Data - ' + str(len(data)) + ' ships\n// Generated from CCP EVE Online Static Data Export\nconst SHIPS_DATA = ' + json.dumps(data, ensure_ascii=False) + ';\n'
with open('ships-data.js', 'w', encoding='utf-8') as f:
    f.write(js)
print('ships-data.js 已更新')
