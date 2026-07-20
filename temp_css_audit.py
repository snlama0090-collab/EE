import os, re

used = set()
for root, dirs, files in os.walk('public/dashboard'):
    for f in files:
        if f.endswith('.php'):
            with open(os.path.join(root, f), 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
                for m in re.finditer(r'class="([^"]+)"', content):
                    for cls in m.group(1).split():
                        used.add(cls.strip())

for f in ['public/index.html', 'public/login.php', 'public/register.php']:
    with open(f, 'r', encoding='utf-8', errors='ignore') as fh:
        content = fh.read()
        for m in re.finditer(r'class="([^"]+)"', content):
            for cls in m.group(1).split():
                used.add(cls.strip())

for cls in sorted(used):
    print(cls)