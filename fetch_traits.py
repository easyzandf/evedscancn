#!/usr/bin/env python3
"""Fetch ship traits (bonuses) from everef ref-data, generate traits-data.js."""
import argparse, json, re, sys, urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.stdout.reconfigure(encoding='utf-8')

_p = argparse.ArgumentParser(description='Fetch ship traits (bonuses) from everef ref-data, generate traits-data.js.')
_p.add_argument('--input', default='eve-ships-data.json', help='input ship data JSON (default: eve-ships-data.json)')
_p.add_argument('--output', default='traits-data.js', help='output traits JS (default: traits-data.js)')
_p.add_argument('--json-out', default='traits-data.json', help='intermediate raw traits JSON (default: traits-data.json)')
_args = _p.parse_args()
INP = _args.input
OUT = _args.output
JSON_OUT = _args.json_out

def get(url, t=40):
    req = urllib.request.Request(url, headers={'User-Agent': 'EVE-DScan-CN/1.0 (ship traits)'})
    with urllib.request.urlopen(req, timeout=t) as r:
        return r.read()

def clean(text):
    """Strip HTML tags like <a href=showinfo:X>Name</a> -> Name"""
    if not text: return ''
    t = re.sub(r'<[^>]+>', '', text)
    return t.strip()

def extract_traits(tid):
    try:
        d = json.loads(get(f'https://ref-data.everef.net/types/{tid}'))
        tr = d.get('traits') or {}
        if not tr: return tid, None, set()
    except Exception:
        return tid, None, set()

    role = []
    skill_ids = set()
    skills = {}

    # role bonuses
    rb = tr.get('role_bonuses') or {}
    items = []
    for k, v in rb.items():
        items.append((v.get('importance', 0), v))
    items.sort(key=lambda x: x[0])
    for _, v in items:
        txt = clean(v.get('bonus_text', {}).get('zh') or v.get('bonus_text', {}).get('en'))
        if txt:
            role.append({'b': v.get('bonus'), 't': txt})

    # skill bonuses
    for skid, bonuses in (tr.get('types') or {}).items():
        skill_ids.add(int(skid))
        blist = []
        bitems = []
        for k, v in bonuses.items():
            bitems.append((v.get('importance', 0), v))
        bitems.sort(key=lambda x: x[0])
        for _, v in bitems:
            txt = clean(v.get('bonus_text', {}).get('zh') or v.get('bonus_text', {}).get('en'))
            if txt:
                blist.append({'b': v.get('bonus'), 't': txt})
        if blist:
            skills[str(skid)] = blist

    if not role and not skills:
        return tid, None, set()

    return tid, {'role': role, 'skills': skills}, skill_ids

with open(INP, encoding='utf-8') as f:
    data = json.load(f)

typeids = []
for d in data:
    m = re.search(r'typeID[:：]\s*(\d+)', d.get('note',''))
    if m: typeids.append(int(m.group(1)))

print(f'提取 {len(typeids)} 艘船的 traits...')
all_skills = set()
traits_map = {}
with ThreadPoolExecutor(max_workers=10) as ex:
    results = ex.map(extract_traits, typeids)
    for tid, tr, skills in results:
        if tr:
            traits_map[tid] = tr
            all_skills |= skills

print(f'有 traits: {len(traits_map)} 艘, 涉及技能: {len(all_skills)} 个')

# fetch skill names (zh) via ESI /universe/types/{id}/?language=zh
def fetch_skill_zh(skid):
    try:
        d = json.loads(get(f'https://esi.evetech.net/latest/universe/types/{skid}/?language=zh'))
        return skid, d.get('name', str(skid))
    except Exception:
        return skid, str(skid)

print('查询技能中文名...')
skill_names = {}
with ThreadPoolExecutor(max_workers=12) as ex:
    for skid, name in ex.map(fetch_skill_zh, all_skills):
        skill_names[skid] = name

# build compact output
out = {}
for tid, tr in traits_map.items():
    entry = {}
    if tr['role']:
        entry['role'] = [(b['b'], b['t']) for b in tr['role']]
    if tr['skills']:
        sk_list = []
        for skid, blist in tr['skills'].items():
            sname = skill_names.get(int(skid), skid)
            sk_list.append([sname, [(b['b'], b['t']) for b in blist]])
        entry['skills'] = sk_list
    out[tid] = entry

with open(JSON_OUT, 'w', encoding='utf-8') as f:
    json.dump(out, f, ensure_ascii=False, indent=2)

js = '// EVE Ship Traits Data\nconst TRAITS_DATA = ' + json.dumps(out, ensure_ascii=False) + ';\n'
with open(OUT, 'w', encoding='utf-8') as f:
    f.write(js)

import os
print(f'{OUT} 生成:', os.path.getsize(OUT), 'bytes')

# sample
tid = '587'
if tid in out:
    print('Rifter traits:', json.dumps(out[tid], ensure_ascii=False, indent=1)[:400])
