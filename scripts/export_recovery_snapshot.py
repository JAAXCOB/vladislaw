"""Create a read-only recovery snapshot for the two B2B Excel reports.

The archive contains copies of the current workbooks and MAX messages from
the requested date. It never writes to chat and never changes either workbook.
"""
from __future__ import annotations

import argparse
import json
import sys
import time
import zipfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
from dotenv import load_dotenv

load_dotenv()
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from webhook.config import settings


MOSCOW_TZ = timezone(timedelta(hours=3))
PAGE_SIZE = 100


def fetch_messages(oldest_ms: int, newest_ms: int) -> list[dict]:
    messages: list[dict] = []
    window_end = newest_ms
    with httpx.Client(timeout=30, verify="/etc/ssl/certs/ca-certificates.crt") as client:
        while True:
            response = client.get(
                f"{settings.MAX_API_BASE}/messages",
                headers={"Authorization": settings.max_bot_token},
                params={
                    "chat_id": settings.max_chat_id,
                    "from": window_end,
                    "to": oldest_ms,
                    "count": PAGE_SIZE,
                },
            )
            response.raise_for_status()
            batch = response.json().get("messages", [])
            if not batch:
                break
            messages.extend(batch)
            if len(batch) < PAGE_SIZE:
                break
            minimum = min(int(item.get("timestamp", 0)) for item in batch)
            if minimum >= window_end or minimum <= oldest_ms:
                break
            window_end = minimum - 1

    # Keep the latest form of an edited message ID and return chronologically.
    by_mid: dict[str, dict] = {}
    without_mid: list[dict] = []
    for item in messages:
        mid = str((item.get("body") or {}).get("mid") or "")
        if mid:
            by_mid[mid] = item
        else:
            without_mid.append(item)
    result = list(by_mid.values()) + without_mid
    result.sort(key=lambda item: int(item.get("timestamp", 0)))
    return result


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--since", default="2026-09-07")
    parser.add_argument("--output", default="data/excel-recovery-snapshot.zip")
    args = parser.parse_args()

    if not settings.max_chat_id:
        raise SystemExit("MAX_CHAT_ID is not configured")
    source_date = datetime.strptime(args.since, "%Y-%m-%d").replace(tzinfo=MOSCOW_TZ)
    oldest_ms = int(source_date.timestamp() * 1000)
    newest_ms = int(time.time() * 1000)
    messages = fetch_messages(oldest_ms, newest_ms)

    workbook_paths = {
        "evacuations.xlsx": Path(settings.excel_file_path),
        "payroll.xlsx": Path(settings.payroll_file_path),
    }
    for label, path in workbook_paths.items():
        if not str(path) or not path.exists():
            raise SystemExit(f"Required workbook is missing: {label}")

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    manifest = {
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "since_moscow": args.since,
        "message_count": len(messages),
        "source_files": {label: path.name for label, path in workbook_paths.items()},
    }
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2))
        archive.writestr("max_messages.json", json.dumps(messages, ensure_ascii=False, indent=2))
        for label, path in workbook_paths.items():
            archive.write(path, label)

    print(json.dumps({"ok": True, "messages": len(messages), "archive": str(output)}))


if __name__ == "__main__":
    main()
