"""Read-only validation of the newest complete Excel backup set."""
from __future__ import annotations

import argparse
import time
from pathlib import Path

import openpyxl
from dotenv import dotenv_values


def configured_workbooks(env_file: Path) -> list[Path]:
    values = dotenv_values(env_file)
    keys = ("EXCEL_FILE_PATH", "PAYROLL_FILE_PATH")
    missing = [key for key in keys if not values.get(key)]
    if missing:
        raise RuntimeError(f"Missing Excel path settings: {', '.join(missing)}")
    return [Path(str(values[key])) for key in keys]


def validate_workbook(path: Path) -> None:
    if not path.is_file() or path.stat().st_size == 0:
        raise RuntimeError(f"Backup workbook is missing or empty: {path}")
    workbook = openpyxl.load_workbook(path, read_only=True, data_only=False)
    try:
        if not workbook.sheetnames:
            raise RuntimeError(f"Backup workbook has no worksheets: {path}")
    finally:
        workbook.close()


def newest_complete_backup(
    backup_root: Path,
    required_names: set[str],
    max_age_hours: float,
) -> Path:
    if not backup_root.is_dir():
        raise RuntimeError(f"Backup root does not exist: {backup_root}")

    candidates = sorted(
        (path for path in backup_root.iterdir() if path.is_dir() and not path.is_symlink()),
        key=lambda path: path.stat().st_mtime,
        reverse=True,
    )
    for candidate in candidates:
        if not all((candidate / name).is_file() for name in required_names):
            continue
        age_hours = (time.time() - candidate.stat().st_mtime) / 3600
        if age_hours > max_age_hours:
            raise RuntimeError(
                f"Newest complete Excel backup is {age_hours:.1f} hours old; "
                f"maximum allowed age is {max_age_hours:.1f} hours"
            )
        for name in sorted(required_names):
            validate_workbook(candidate / name)
        return candidate
    raise RuntimeError("No complete Excel backup contains every configured workbook")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--backup-root", type=Path, required=True)
    parser.add_argument("--max-age-hours", type=float, default=72)
    args = parser.parse_args()

    workbooks = configured_workbooks(args.env_file)
    backup = newest_complete_backup(
        args.backup_root,
        {path.name for path in workbooks},
        args.max_age_hours,
    )
    print(f"validated Excel backup: {backup}")


if __name__ == "__main__":
    main()
