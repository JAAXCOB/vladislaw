"""Restore only corrupted configured Excel workbooks from the newest valid backup."""
from __future__ import annotations

import os
import shutil
import tempfile
from pathlib import Path

import openpyxl
from dotenv import load_dotenv


def is_valid(path: Path) -> bool:
    try:
        workbook = openpyxl.load_workbook(path, read_only=True, data_only=False)
        workbook.close()
        return True
    except Exception:
        return False


def restore(path: Path, backups_root: Path) -> str:
    if is_valid(path):
        return f"valid:{path.name}"

    candidates = sorted(
        (candidate for candidate in backups_root.rglob(path.name) if is_valid(candidate)),
        key=lambda candidate: candidate.stat().st_mtime,
        reverse=True,
    )
    if not candidates:
        raise RuntimeError(f"No valid backup found for corrupted workbook: {path}")

    source = candidates[0]
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix=f".{path.name}.", delete=False) as handle:
        temporary = Path(handle.name)
    try:
        shutil.copy2(source, temporary)
        if not is_valid(temporary):
            raise RuntimeError(f"Backup validation failed: {source}")
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)
    return f"restored:{path.name}:from:{source}"


def main() -> None:
    load_dotenv()
    configured = [
        Path(os.environ["EXCEL_FILE_PATH"]),
        Path(os.environ["PAYROLL_FILE_PATH"]),
    ]
    backups_root = Path("data/excel/backups")
    for workbook in configured:
        print(restore(workbook, backups_root))


if __name__ == "__main__":
    main()
