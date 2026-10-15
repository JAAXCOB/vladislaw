"""Create a validated, read-only copy of both configured Excel workbooks."""
from __future__ import annotations

import argparse
import shutil
from datetime import datetime, timezone
from pathlib import Path

from scripts.verify_excel_backup import configured_workbooks, validate_workbook


def create_backup(env_file: Path, backup_root: Path) -> Path:
    sources = configured_workbooks(env_file)
    for source in sources:
        validate_workbook(source)

    backup_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    destination = backup_root / backup_id
    destination.mkdir(parents=True, exist_ok=False)
    try:
        for source in sources:
            copied = destination / source.name
            shutil.copy2(source, copied)
            validate_workbook(copied)
    except Exception:
        shutil.rmtree(destination)
        raise
    return destination


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env-file", type=Path, required=True)
    parser.add_argument("--backup-root", type=Path, required=True)
    args = parser.parse_args()
    destination = create_backup(args.env_file, args.backup_root)
    print(f"created validated Excel backup: {destination}")


if __name__ == "__main__":
    main()
