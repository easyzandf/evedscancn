#!/usr/bin/env python3
"""Build items-data.js from everef reference-data (types.json + groups.json).

Runs on the VPS where the 14MB reference-data tarball is available. Extracts
en+zh names for Module(7)/Charge(8)/Drone(18) types via mmap streaming so the
252MB types.json is never fully loaded into RAM.
"""
import json, mmap, os, sys

BASE = '/tmp/refx'          # extracted reference-data
OUT = '/tmp/items-data.js'  # output

def main():
    cats = json.load(open(os.path.join(BASE, 'categories.json')))
    groups = json.load(open(os.path.join(BASE, 'groups.json')))

    # 1) needed typeIDs for Module/Charge/Drone categories
    need = set()
    for cid in ('7', '8', '18'):
        c = cats.get(cid)
        if not c:
            continue
        for gid in c.get('group_ids', []):
            g = groups.get(str(gid))
            if g:
                need.update(str(x) for x in g.get('type_ids', []))
    sys.stdout.write('needed: %d\n' % len(need)); sys.stdout.flush()

    # 2) stream types.json, keep names for needed types
    f = open(os.path.join(BASE, 'types.json'), 'rb')
    mm = mmap.mmap(f.fileno(), 0, access=mmap.ACCESS_READ)
    n = len(mm)

    def skip_ws(p):
        while p < n and mm[p] in b' \t\r\n':
            p += 1
        return p

    def parse_string(p):
        # mm[p] == '"'
        p += 1
        while p < n:
            c = mm[p]
            if c == 0x5c:
                p += 2
                continue
            if c == 0x22:
                return p + 1
            p += 1
        return p

    def capture_value(p):
        c = mm[p]
        if c == 0x22:
            return parse_string(p)
        if c in b'tfn':
            while p < n and mm[p] not in b',}':
                p += 1
            return p
        if c == 0x7b or c == 0x5d or c == 0x5b:   # { or [
            depth = 0
            while p < n:
                cc = mm[p]
                if cc == 0x22:
                    p = parse_string(p)
                    continue
                if cc == 0x7b or cc == 0x5b:
                    depth += 1
                elif cc == 0x7d or cc == 0x5d:
                    depth -= 1
                    if depth == 0:
                        return p + 1
                p += 1
            return p
        while p < n and mm[p] in b'0123456789+-.eE':
            p += 1
        return p

    pos = 0
    while pos < n and mm[pos] != 0x7b:
        pos += 1
    pos += 1  # skip '{'

    out = {}
    while True:
        pos = skip_ws(pos)
        if pos >= n or mm[pos] == 0x7d:
            break
        if mm[pos] != 0x22:
            pos += 1
            continue
        kstart = pos + 1
        pos = parse_string(pos)
        key = mm[kstart:pos - 1].decode('utf-8', 'ignore')
        pos = skip_ws(pos)
        if mm[pos] == 0x3a:
            pos += 1
        pos = skip_ws(pos)
        vstart = pos
        pos = capture_value(pos)
        if key in need:
            try:
                rec = json.loads(mm[vstart:pos])
                nm = rec.get('name') or {}
                en = nm.get('en') or ''
                zh = nm.get('zh') or ''
                if en or zh:
                    out[key] = {'en': en, 'zh': zh}
            except Exception:
                pass

    mm.close()
    f.close()
    sys.stdout.write('extracted: %d\n' % len(out)); sys.stdout.flush()

    js = '// EVE item names (en + zh) for buy-list matching\nconst ITEMS_DATA = ' + json.dumps(out, ensure_ascii=False) + ';\n'
    with open(OUT, 'w', encoding='utf-8') as fo:
        fo.write(js)
    sys.stdout.write('items-data.js: %d bytes\n' % os.path.getsize(OUT))

if __name__ == '__main__':
    main()
