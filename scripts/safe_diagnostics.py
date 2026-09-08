"""Print privacy-safe production counters for deployment verification."""
from __future__ import annotations

import datetime as dt
import json
import re
import subprocess
from pathlib import Path


def journal_rows() -> list[tuple[str, str]]:
    result = subprocess.run(
        ["journalctl", "-u", "max-webhook.service", "--since", "today", "--no-pager", "-o", "json"],
        check=False,
        capture_output=True,
        text=True,
    )
    rows: list[tuple[str, str]] = []
    for line in result.stdout.splitlines():
        try:
            item = json.loads(line)
            micros = int(item.get("__REALTIME_TIMESTAMP", 0))
            stamp = dt.datetime.fromtimestamp(micros / 1_000_000, tz=dt.timezone.utc).isoformat()
            rows.append((stamp, str(item.get("MESSAGE", ""))))
        except Exception:
            continue
    return rows


def json_value(path: Path, fallback):
    try:
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else fallback
    except Exception:
        return fallback


rows = journal_rows()


def matches(pattern: str) -> list[str]:
    return [stamp for stamp, message in rows if pattern in message]


def show(name: str, pattern: str) -> None:
    stamps = matches(pattern)
    print(f"{name}={len(stamps)}")
    print(f"last_{name}_at={stamps[-1] if stamps else 'none'}")


print("SAFE_DIAGNOSTICS_BEGIN")
show("max_messages_today", "MESSAGE_CREATED")
show("non_closed_messages_today", "Message does not report a closed job")
show("sync_failures_today", "AV Rescue synchronization failed")
show("excel_failures_today", "Failed to write to Excel")
show("excel_writes_today", "Written to sheet")
show("excel_duplicates_today", "Duplicate already exists")

queue = json_value(Path("data/av_rescue_sync_queue.json"), [])
print(f"partner_sync_queue={len(queue) if isinstance(queue, list) else -1}")

state = json_value(Path("data/open_jobs_state.json"), {"chats": {}})
try:
    tracked = sum(len((chat or {}).get("open_jobs", {})) for chat in state.get("chats", {}).values())
except Exception:
    tracked = -1
print(f"tracked_open_jobs={tracked}")

failure_types: dict[str, int] = {}
for index, (_, message) in enumerate(rows):
    if "Failed to write to Excel" not in message:
        continue
    window = "\n".join(item[1] for item in rows[index:index + 20])
    found = re.findall(r"([A-Za-z_][A-Za-z0-9_]*(?:Error|Exception))(?::|$)", window)
    error_type = found[-1] if found else "UnknownError"
    failure_types[error_type] = failure_types.get(error_type, 0) + 1
print("excel_failure_types=" + ",".join(f"{name}:{count}" for name, count in sorted(failure_types.items())))
print("SAFE_DIAGNOSTICS_END")
