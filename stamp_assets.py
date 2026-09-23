#!/usr/bin/env python3
"""Stamp content hashes into the ?v= of the data files index.html references.

Those files are served with a long cache lifetime (see deploy/dscan-dpdns.conf), so
their URL has to change whenever their bytes do. Deriving the version from the
content makes that automatic: a hand-written number would eventually be forgotten,
and forgetting it once means returning visitors keep the old file until the cache
expires - the exact staleness the long cache was supposed to be safe about.

Only real URL positions are rewritten (the value of a src/href attribute, or a
string assigned to .src). The bare filenames also appear in comments and in the
About page's project tree, and those must stay as they are.

    python stamp_assets.py           rewrite index.html in place
    python stamp_assets.py --check   report what is stale, write nothing, exit 1
"""
import hashlib
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
INDEX = ROOT / 'index.html'

ASSETS = [
    'vendor/html2canvas.min.js',
    'apple-touch-icon.png',
    'items-data.js',
    'ships-data.js',
    'traits-data.js',
    'favicon.ico',
    'favicon.png',
]


def digest(name: str) -> str:
    return hashlib.md5((ROOT / name).read_bytes()).hexdigest()[:8]


def stamp(html: str, name: str, version: str):
    """Return (new_html, [(old_url, new_url), ...]) for one asset."""
    # A quoted/slashed prefix and a quoted suffix pin this to `="file"`, `='/file'`,
    # `="dir/file"` - i.e. an actual reference, never prose.
    pattern = re.compile(r'(?<=["\'/])(' + re.escape(name) + r')(\?v=[0-9a-zA-Z]+)?(?=["\'])')
    changes = []

    def repl(m):
        old = m.group(1) + (m.group(2) or '')
        new = f'{m.group(1)}?v={version}'
        if old != new:
            changes.append((old, new))
        return new

    return pattern.sub(repl, html), changes


def main() -> int:
    check_only = '--check' in sys.argv[1:]
    html = INDEX.read_text(encoding='utf-8')
    original = html
    stale = []
    missing = []

    for name in ASSETS:
        if not (ROOT / name).exists():
            print(f'error: asset missing from the working tree: {name}', file=sys.stderr)
            return 2
        version = digest(name)
        html, changes = stamp(html, name, version)
        if changes:
            stale.append(name)
            for old, new in changes:
                print(f'{"stale" if check_only else "stamp"}  {old}  ->  {new}')

    if html == original:
        print(f'up to date: {len(ASSETS)} assets referenced from index.html')
        return 0

    if check_only:
        print(f'\n{len(stale)} asset(s) stale: {", ".join(stale)}', file=sys.stderr)
        print('run "python stamp_assets.py" and commit the result', file=sys.stderr)
        return 1

    INDEX.write_text(html, encoding='utf-8', newline='')  # keep LF, the repo is pinned
    print(f'\nrewrote index.html: {len(stale)} asset(s) updated')
    return 0


if __name__ == '__main__':
    sys.exit(main())
