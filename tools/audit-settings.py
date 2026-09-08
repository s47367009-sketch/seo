#!/usr/bin/env python3
"""Report settings keys referenced by the plugin but missing from Settings::defaults().

Crude but dependable: split defaults() into per-group slices, harvest every
'key' => token inside the slice, then diff against the keys the code reads.
"""
import re, sys, glob, os

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
src = open(os.path.join(root, 'includes', 'Settings.php'), encoding='utf-8').read()

start = src.index('public static function defaults()')
end = src.index("\n\t}\n", start)
body = src[start:end]

# Group markers: lines at exactly 3 tabs -> "\t\t\t'name' => array("
marks = [(m.start(), m.group(1)) for m in re.finditer(r"^\t{3}'([a-z_0-9]+)'\s*=>\s*array\(\s*$", body, re.M)]
marks.append((len(body), '__END__'))

groups = {}
for idx in range(len(marks) - 1):
    name = marks[idx][1]
    slice_ = body[marks[idx][0]:marks[idx + 1][0]]
    keys = set(re.findall(r"^\t{4,}'([a-z_0-9.]+)'\s*=>", slice_, re.M))
    keys |= set(re.findall(r"^\t{4,}'([a-z_0-9.]+)':\s", slice_, re.M))
    groups[name] = keys

refs = {}
for path in glob.glob(os.path.join(root, 'includes', '**', '*.php'), recursive=True) + [os.path.join(root, 'hoosh-seo.php')]:
    text = open(path, encoding='utf-8').read()
    rel = os.path.relpath(path, root).replace('includes/', '')
    for m in re.finditer(r"settings[^'\"]{0,14}(?:get|on)\(\s*'([a-z_0-9.]+)'", text):
        refs.setdefault(m.group(1), set()).add(rel)
    for m in re.finditer(r"->get\(\s*\$settings\[[^\]]+\]\s*\|\|\s*'([a-z_0-9.]+)'", text):
        refs.setdefault(m.group(1), set()).add(rel)

missing = {}
for key, files in sorted(refs.items()):
    if '.' not in key:
        continue
    grp, leaf = key.split('.', 1)
    if grp not in groups:
        missing[key] = files | {'!! group missing'}
        continue
    tail = leaf.split('.')[0]
    if tail in groups[grp]:
        continue
    missing[key] = files

print('groups: %d, referenced: %d, missing: %d\n' % (len(groups), len(refs), len(missing)))
for key, files in sorted(missing.items()):
    print('%-40s %s' % (key, ', '.join(sorted(files))))

if '--list' in sys.argv:
    for g in sorted(groups):
        print('\n# %s: %s' % (g, ' '.join(sorted(groups[g]))))
