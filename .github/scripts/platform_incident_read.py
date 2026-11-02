"""Read-only, redacted diagnostics for the incidents reported on 2026-11-02."""
import datetime
import json
import pathlib
import re
import subprocess

root = pathlib.Path('/home/clever-web')
ids = {'7539DE4A9AF6', '9577AAE9F00F', '0D8D94FC654C', 'E9CEE9D4DC42',
       '0DE31A6B1B82', 'B1F62CEC5E15', 'F7E70A1B3775', '878B37571128'}
windows = [('2026-11-01 10:52:40', '2026-11-01 10:53:20'),
           ('2026-11-01 20:09:50', '2026-11-01 20:10:20'),
           ('2026-11-02 00:00:00', '2026-11-02 00:07:00')]


def clean(value):
    value = re.sub(r'https?://\S+', '[url]', str(value))
    value = re.sub(r'[\w.+%-]+@[\w.-]+\.[A-Za-z]{2,}', '[email]', value)
    value = re.sub(r'\b\d{6,}:[A-Za-z0-9_-]+', '[token]', value)
    value = re.sub(r'(?i)(token|password|authorization|secret)[=: ]+[^\s,]+', r'\1=[redacted]', value)
    return re.sub(r'\s+', ' ', value).strip()


lines = ['UTC: ' + datetime.datetime.now(datetime.timezone.utc).isoformat()]
for args in [['git', 'rev-parse', 'HEAD'], ['git', 'status', '--short']]:
    result = subprocess.run(args, cwd=root, capture_output=True, text=True, timeout=10)
    lines.append(' '.join(args[1:]) + ': ' + clean(result.stdout or '(clean)')[:350])

groups = {}


def finish(header, body):
    if not header:
        return
    timestamp, level, message = header
    diagnostic = next((item for item in ids if item in message), None)
    selected = diagnostic or any(start <= timestamp <= end for start, end in windows)
    if not selected or (level not in {'ERROR', 'CRITICAL', 'WARNING'} and not diagnostic):
        return
    if diagnostic:
        context = json.loads(message.split(' ', 3)[-1]) if message.startswith('Admin error diagnostic {') else {}
        summary = 'Alert ' + diagnostic + ': ' + clean(context.get('exception_class', ''))
        summary += ' at ' + clean(context.get('source', ''))
    else:
        summary = clean(message.split(' {', 1)[0])
        frames = re.findall(r'#\d+ ([^\n(]+)\((\d+)\): ([^\n(]+)', body)
        app = next((frame for frame in frames if '/app/' in frame[0]), None)
        if app:
            summary += ' | app: ' + app[0].replace('/var/www/html/', '') + ':' + app[1] + ' ' + app[2]
    key = (level, summary)
    group = groups.setdefault(key, [timestamp, timestamp, 0])
    group[1] = timestamp
    group[2] += 1


for name in ['laravel-2026-11-01.log', 'laravel-2026-11-02.log']:
    path = root / 'application/storage/logs' / name
    if not path.exists():
        lines.append('Missing log: ' + name)
        continue
    header, body = None, ''
    with path.open(errors='replace') as stream:
        for raw in stream:
            match = re.match(r'^\[([^]]+)\] \w+\.(\w+): (.*)', raw)
            if match:
                finish(header, body)
                header, body = match.groups(), ''
            elif len(body) < 200000:
                body += raw
    finish(header, body)

for (level, message), (first, last, count) in sorted(groups.items(), key=lambda item: item[1][0]):
    lines.append(f'{first} {level} x{count}: {message}'[:650])

archives = sorted((root / 'application/storage/app/CLEVER-PLATFORM').glob('*.zip'),
                  key=lambda path: path.stat().st_mtime, reverse=True)
for path in archives[:2]:
    lines.append(f'Backup: {path.name}, bytes={path.stat().st_size}, mtime={path.stat().st_mtime}')
print(json.dumps(lines, ensure_ascii=False))
