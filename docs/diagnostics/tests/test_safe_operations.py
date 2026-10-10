import hashlib
import stat
import zipfile

import pytest

from scripts.safe_inventory import inventory, compare
from scripts.verify_restore import restore


def backup(tmp_path, files, mode=None):
    archive = tmp_path / 'backup.zip'
    with zipfile.ZipFile(archive, 'w') as z:
        for name, value in files.items():
            info = zipfile.ZipInfo(name)
            if mode:
                info.external_attr = mode << 16
            z.writestr(info, value)
    manifest = {'version': 1, 'files': {name: {'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest()} for name, value in files.items()}}
    return archive, manifest


def test_reconciliation_detects_drift_and_missing_without_contents(tmp_path):
    (tmp_path / 'code.php').write_text('private fixture content')
    first = inventory(tmp_path, ['code.php', 'missing.php'])
    assert 'private fixture content' not in str(first)
    (tmp_path / 'code.php').write_text('changed')
    assert compare(first, inventory(tmp_path, ['code.php', 'missing.php'])) == {'code.php': 'different_or_missing', 'missing.php': 'different_or_missing'}


@pytest.mark.parametrize('name', ['../secret', '/etc/passwd', 'x\\y'])
def test_inventory_rejects_unsafe_paths(tmp_path, name):
    with pytest.raises(ValueError):
        inventory(tmp_path, [name])


def test_inventory_rejects_symlinks(tmp_path):
    (tmp_path / 'link').symlink_to('/etc/passwd')
    with pytest.raises(ValueError):
        inventory(tmp_path, ['link'])


def test_restore_roundtrip_and_no_overwrite(tmp_path):
    files = {'data/orders.php': b'<?php exit; ?>\n[]', 'data/queue.json': b'[]'}
    archive, manifest = backup(tmp_path, files)
    target = tmp_path / 'stand'
    assert restore(archive, manifest, target)['verified_files'] == 2
    assert (target / 'data/orders.php').read_bytes() == files['data/orders.php']
    with pytest.raises(ValueError):
        restore(archive, manifest, target)
    assert (target / 'data/orders.php').read_bytes() == files['data/orders.php']


def test_restore_rejects_corruption_before_publishing(tmp_path):
    archive, manifest = backup(tmp_path, {'orders.json': b'[]'})
    manifest['files']['orders.json']['sha256'] = '0' * 64
    with pytest.raises(ValueError):
        restore(archive, manifest, tmp_path / 'stand')
    assert not (tmp_path / 'stand').exists()


@pytest.mark.parametrize('name', ['../escape', '/escape'])
def test_restore_rejects_traversal(tmp_path, name):
    archive, manifest = backup(tmp_path, {name: b'x'})
    with pytest.raises(ValueError):
        restore(archive, manifest, tmp_path / 'stand')
    assert not (tmp_path / 'stand').exists()


def test_restore_rejects_symlinks(tmp_path):
    archive, manifest = backup(tmp_path, {'link': b'/etc/passwd'}, stat.S_IFLNK | 0o777)
    with pytest.raises(ValueError):
        restore(archive, manifest, tmp_path / 'stand')


def test_restore_rejects_oversized_backup(tmp_path):
    archive, manifest = backup(tmp_path, {'data': b'123'})
    with pytest.raises(ValueError):
        restore(archive, manifest, tmp_path / 'stand', max_bytes=2)


def test_restore_rejects_unmanifested_files(tmp_path):
    archive, manifest = backup(tmp_path, {'one': b'1', 'two': b'2'})
    del manifest['files']['two']
    with pytest.raises(ValueError):
        restore(archive, manifest, tmp_path / 'stand')
