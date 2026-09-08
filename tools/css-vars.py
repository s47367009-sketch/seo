#!/usr/bin/env python3
"""
CSS custom-property audit.

Every `var(--x)` in app.css must be defined somewhere in the file (or on :root
by the browser). A typo'd or invented variable silently falls back to its
fallback value — which is how a component ends up grey while the rest of the
app follows the accent and dark-mode tokens.
"""
import re
import sys
import pathlib

path = pathlib.Path(__file__).resolve().parent.parent / 'assets' / 'app.css'
src = path.read_text(encoding='utf-8')

# Strip comments so examples in prose don't count.
body = re.sub(r'/\*.*?\*/', '', src, flags=re.S)

defined = set(re.findall(r'(?m)^\s*(--[a-zA-Z0-9-]+)\s*:', body))
used = {}
for m in re.finditer(r'var\(\s*(--[a-zA-Z0-9-]+)', body):
    name = m.group(1)
    line = body[:m.start()].count('\n') + 1
    used.setdefault(name, []).append(line)

missing = {k: v for k, v in used.items() if k not in defined}

print(f'defined tokens : {len(defined)}')
print(f'distinct used  : {len(used)}')
print(f'undefined used : {len(missing)}')
for name, lines in sorted(missing.items()):
    shown = ', '.join(str(x) for x in lines[:6])
    print(f'  ✗ {name}  (lines {shown})')

# Fallback hygiene: a var() with no fallback is fine when the token exists,
# but flag the ones that do have a fallback pointing at a hardcoded colour,
# since those mask exactly this class of bug.
silent = re.findall(r'var\(\s*(--[a-zA-Z0-9-]+)\s*,\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\))', body)
if silent:
    print(f'\nvars with hardcoded colour fallbacks (mask typos): {len(silent)}')
    for name, fb in sorted(set(silent))[:10]:
        print(f'  ! {name} → {fb}')

sys.exit(1 if missing else 0)
