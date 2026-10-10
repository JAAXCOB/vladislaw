"""Explicit-path, read-only inventory. Never prints file contents."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath


def safe_path(root, name):
    path = PurePosixPath(name)
    if not name or path.is_absolute() or '..' in path.parts or '\\' in name:
        raise ValueError('Unsafe relative path')
    root = Path(root).resolve(strict=True)
    target = root
    for part in path.parts:
        target /= part
        if target.is_symlink():
            raise ValueError('Symlinks are forbidden')
    return target


def inventory(root, names):
    rows = {}
    for name in sorted(set(names)):
        target = safe_path(root, name)
        if not target.exists():
            rows[name] = {'state': 'missing'}
            continue
        flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
        with os.fdopen(os.open(target, flags), 'rb') as stream:
            import stat
            before = os.fstat(stream.fileno())
            if not stat.S_ISREG(before.st_mode):
                raise ValueError('Only regular files allowed')
            digest = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b''):
                digest.update(chunk)
            after = os.fstat(stream.fileno())
        current = target.stat()
        if (before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns) or (current.st_ino, current.st_dev) != (after.st_ino, after.st_dev):
            raise ValueError('File changed during inventory; retry snapshot')
        rows[name] = {'state': 'present', 'bytes': after.st_size, 'sha256': digest.hexdigest()}
    return {'version': 1, 'files': rows}


def compare(left, right):
    return {name: 'same' if left['files'].get(name, {}).get('state') == 'present' and left['files'].get(name) == right['files'].get(name) else 'different_or_missing'
            for name in sorted(set(left['files']) | set(right['files']))}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    scan = sub.add_parser('scan')
    scan.add_argument('--root', required=True)
    scan.add_argument('--paths', required=True, help='JSON array of explicit relative paths; no recursive scan')
    diff = sub.add_parser('compare')
    diff.add_argument('left')
    diff.add_argument('right')
    args = parser.parse_args()
    try:
        if args.action == 'scan':
            result = inventory(args.root, json.loads(Path(args.paths).read_text()))
        else:
            result = compare(json.loads(Path(args.left).read_text()), json.loads(Path(args.right).read_text()))
    except (OSError, ValueError, KeyError, TypeError):
        parser.exit(2, 'Inventory failed; check paths and snapshot consistency locally.\n')
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
