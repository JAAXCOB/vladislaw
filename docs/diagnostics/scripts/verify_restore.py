"""Offline ZIP restore drill into a NEW directory with an external trusted manifest."""
import argparse
import hashlib
import json
import shutil
import stat
import tempfile
import zipfile
from pathlib import Path
from scripts.safe_inventory import safe_path


def restore(archive, manifest, target, max_bytes=256 * 1024 * 1024):
    target = Path(target)
    if target.exists() or target.is_symlink():
        raise ValueError('Target must not exist')
    parent = target.parent.resolve(strict=True)
    if target.name in ('', '.', '..'):
        raise ValueError('Invalid target')
    target = parent / target.name
    if manifest.get('version') != 1 or not isinstance(manifest.get('files'), dict):
        raise ValueError('Invalid trusted manifest')
    expected = manifest['files']
    with tempfile.TemporaryDirectory(prefix='.avr-restore-', dir=parent) as temp:
        with zipfile.ZipFile(archive) as source:
            entries = source.infolist()
            names = [entry.filename for entry in entries]
            if len(names) != len(set(names)) or set(names) != set(expected):
                raise ValueError('Archive entries differ from trusted manifest')
            if sum(entry.file_size for entry in entries) > max_bytes:
                raise ValueError('Archive too large')
            for entry in entries:
                destination = safe_path(temp, entry.filename)
                mode = entry.external_attr >> 16
                if entry.is_dir() or stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in (0, stat.S_IFREG)):
                    raise ValueError('Non-file archive member')
                destination.parent.mkdir(parents=True, exist_ok=True)
                digest = hashlib.sha256()
                size = 0
                with source.open(entry) as src, destination.open('xb') as dst:
                    while chunk := src.read(1024 * 1024):
                        size += len(chunk)
                        if size > max_bytes:
                            raise ValueError('Archive too large')
                        digest.update(chunk)
                        dst.write(chunk)
                if size != expected[entry.filename]['bytes'] or digest.hexdigest() != expected[entry.filename]['sha256']:
                    raise ValueError('Backup checksum mismatch')
                destination.chmod(0o600)
        # Exclusive creation; never overwrite an existing directory, including a race.
        target.mkdir(mode=0o700)
        for child in Path(temp).iterdir():
            shutil.move(str(child), target / child.name)
    return {'verified_files': len(expected), 'restored_bytes': sum(row['bytes'] for row in expected.values())}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--target', required=True)
    args = parser.parse_args()
    try:
        result = restore(args.archive, json.loads(Path(args.manifest).read_text()), args.target)
    except (OSError, ValueError, KeyError, zipfile.BadZipFile, RuntimeError):
        parser.exit(2, 'Restore rejected; inspect the backup in the isolated workspace.\n')
    print(json.dumps(result))


if __name__ == '__main__':
    main()
