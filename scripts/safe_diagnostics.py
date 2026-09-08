"""Print privacy-safe production counters for deployment verification."""
from __future__ import annotations

import json
import subprocess
from pathlib import Path


def journal_count(pattern: str) -> int:
    result = subprocess.run(
        ["journalctl", "-u", "max-webhook.service", "--since", "today", "--no-pager"],
        check=False,
        capture_output=True,
        text=True,
    )
    return result.stdout.count(pattern)


def json_value(path: Path, fallback):
    try:
        return json.loads(path.read_text(encoding="utf-8")) if path.exists() else fallback
    except Exception:
        return fallback


print("SAFE_DIAGNOSTICS_BEGIN")
print(f"max_messages_today={journal_count('MESSAGE_CREATED')}")
print(f"non_closed_messages_today={journal_count('Message does not report a closed job')}")
print(f"sync_failures_today={journal_count('AV Rescue synchronization failed')}")
print(f"excel_failures_today={journal_count('Failed to write to Excel')}")

queue = json_value(Path("data/av_rescue_sync_queue.json"), [])
print(f"partner_sync_queue={len(queue) if isinstance(queue, list) else -1}")

state = json_value(Path("data/open_jobs_state.json"), {"chats": {}})
try:
    tracked = sum(len((chat or {}).get("open_jobs", {})) for chat in state.get("chats", {}).values())
except Exception:
    tracked = -1
print(f"tracked_open_jobs={tracked}")
print("SAFE_DIAGNOSTICS_END")
