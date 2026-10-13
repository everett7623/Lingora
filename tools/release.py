"""Build a reproducible WordPress installation ZIP from explicit runtime files."""
from pathlib import Path
import argparse
import hashlib
import json
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output', type=Path, help='New destination ZIP; existing files are never overwritten')
parser.add_argument('--check', action='store_true', help='Validate the source manifest without creating files')
args = parser.parse_args()
header = (ROOT / 'lingora.php').read_text(encoding='utf-8')
readme = (ROOT / 'readme.txt').read_text(encoding='utf-8')
versions = [
    re.search(r'\* Version:\s*([^\s]+)', header).group(1),
    re.search(r"define\( 'LINGORA_VERSION', '([^']+)' \)", header).group(1),
    re.search(r'^Stable tag:\s*(\S+)', readme, re.M).group(1),
]
if len(set(versions)) != 1:
    raise SystemExit(f'Version mismatch: {versions}')
version = versions[0]
if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?', version):
    raise SystemExit('Invalid release version')
paths = [ROOT / f for f in ('lingora.php', 'uninstall.php', 'readme.txt', 'LICENSE')]
allowed = {'.php', '.css', '.js', '.svg', '.png', '.jpg', '.jpeg', '.webp', '.po', '.mo', '.pot', '.json'}
for directory in ('admin', 'includes', 'public', 'assets', 'languages'):
    folder = ROOT / directory
    if folder.is_symlink():
        raise SystemExit(f'Symlink directory is not a release source: {folder}')
    if not folder.exists():
        continue
    for file in sorted(folder.rglob('*')):
        if file.is_symlink():
            raise SystemExit(f'Symlink is not a release source: {file}')
        if file.is_file():
            if file.suffix.lower() not in allowed:
                raise SystemExit(f'Unexpected runtime file: {file.relative_to(ROOT)}')
            paths.append(file)
manifest = []
for file in sorted(set(paths)):
    relative = file.relative_to(ROOT).as_posix()
    data = file.read_bytes()
    manifest.append({'path': 'lingora/' + relative, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    if file.suffix == '.php' and b'\xef\xbb\xbf' == data[:3]:
        raise SystemExit(f'PHP BOM can corrupt output: {relative}')
if len((ROOT / 'LICENSE').read_bytes()) < 17000:
    raise SystemExit('The full GPL license text is required')
if '= External services =' not in readme.lower() and 'external services' not in readme.lower():
    raise SystemExit('readme.txt must disclose external services')
if args.check:
    print(json.dumps({'version': version, 'files': len(manifest), 'source_manifest': manifest}, indent=2))
else:
    output = args.output or ROOT / f'lingora-{version}.zip'
    if not output.is_absolute():
        output = ROOT / output
    if output.exists() or output.with_suffix('.manifest.json').exists():
        raise SystemExit('Destination exists; choose a new output name rather than overwriting an earlier release')
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'x', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for item in manifest:
            info = zipfile.ZipInfo(item['path'], date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, (ROOT / item['path'][len('lingora/'):]).read_bytes())
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None or archive.namelist() != [item['path'] for item in manifest]:
            raise SystemExit('ZIP verification failed')
    report = {'version': version, 'artifact': output.name, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest(), 'files': manifest}
    output.with_suffix('.manifest.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'artifact': str(output), 'version': version, 'files': len(manifest), 'sha256': report['sha256']}, indent=2))
