"""Atomically restore configured Excel files from one explicitly selected backup."""
from __future__ import annotations

import argparse
import os
import shutil
import tempfile
from datetime import datetime, timezone
from pathlib import Path

import openpyxl
from dotenv import dotenv_values


def configured_workbooks(env_file: Path, data_root: Path) -> list[Path]:
    values = dotenv_values(env_file)
    keys = ("EXCEL_FILE_PATH", "PAYROLL_FILE_PATH")
    missing = [key for key in keys if not values.get(key)]
    if missing:
        raise RuntimeError(f"Missing Excel path settings: {', '.join(missing)}")
    resolved_root = data_root.resolve()
    workbooks = []
    for key in keys:
        configured = Path(str(values[key]))
        if not configured.is_absolute():
            configured = env_file.parent / configured
        destination = configured.resolve()
        if not destination.is_relative_to(resolved_root):
            raise RuntimeError(f"{key} must stay below {resolved_root}: {destination}")
        workbooks.append(destination)
    return workbooks


def validate_workbook(path: Path) -> None:
    if not path.is_file() or path.stat().st_size == 0:
        raise RuntimeError(f"Workbook is missing or empty: {path}")
    workbook = openpyxl.load_workbook(path, read_only=True, data_only=False)
    try:
        if not workbook.sheetnames:
            raise RuntimeError(f"Workbook has no worksheets: {path}")
    finally:
        workbook.close()


def stage_replacements(backup_dir: Path, destinations: list[Path]) -> list[tuple[Path, Path]]:
    staged: list[tuple[Path, Path]] = []
    for destination in destinations:
        source = backup_dir / destination.name
        validate_workbook(source)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(
            dir=destination.parent,
            prefix=f".{destination.stem}.restore-",
            suffix=destination.suffix,
            delete=False,
        ) as handle:
            temporary = Path(handle.name)
        shutil.copy2(source, temporary)
        validate_workbook(temporary)
        reference = destination if destination.exists() else destination.parent
        stat = reference.stat()
        if hasattr(os, "chown"):
            os.chown(temporary, stat.st_uid, stat.st_gid)
        staged.append((temporary, destination))
    return staged


def restore(backup_dir: Path, env_file: Path, data_root: Path, pre_restore_root: Path) -> Path:
    if not backup_dir.is_dir() or backup_dir.is_symlink():
        raise RuntimeError(f"Selected backup directory is invalid: {backup_dir}")

    destinations = configured_workbooks(env_file, data_root)
    staged = stage_replacements(backup_dir, destinations)
    timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    safety_backup = pre_restore_root / f"pre-restore-{timestamp}"
    safety_backup.mkdir(parents=True, exist_ok=False)

    replaced: list[Path] = []
    try:
        for destination in destinations:
            if destination.exists():
                shutil.copy2(destination, safety_backup / destination.name)
        for temporary, destination in staged:
            os.replace(temporary, destination)
            replaced.append(destination)
    except Exception:
        for destination in replaced:
            previous = safety_backup / destination.name
            if previous.exists():
                shutil.copy2(previous, destination)
        raise
    finally:
        for temporary, _ in staged:
            temporary.unlink(missing_ok=True)
    return safety_backup


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--backup-dir", type=Path, required=True)
    parser.add_argument("--data-root", type=Path, required=True)
    parser.add_argument("--pre-restore-root", type=Path, required=True)
    args = parser.parse_args()
    safety_backup = restore(
        args.backup_dir,
        args.env_file,
        args.data_root,
        args.pre_restore_root,
    )
    print(f"Excel restore completed; previous files saved in {safety_backup}")


if __name__ == "__main__":
    main()
