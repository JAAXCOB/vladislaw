"""
Tracks license plates from "new job request" messages until a matching
"closed job" message appears for the same plate, so the caller can remind
about anything still open after at least one full check cycle has passed.

State is scoped per chat_id (data/open_jobs_state.json), so testing in a
test group can never mix with production tracking.
"""
from __future__ import annotations

import json
import os
import re
import time
from copy import deepcopy
from pathlib import Path
from typing import Optional

from webhook.storage import atomic_write_json, file_lock

STATE_PATH = Path(__file__).parent.parent / "data" / "open_jobs_state.json"


CYRILLIC_PLATE_CHARS = str.maketrans({
    "A": "А", "B": "В", "E": "Е", "K": "К", "M": "М", "H": "Н",
    "O": "О", "P": "Р", "C": "С", "T": "Т", "Y": "У", "X": "Х",
})


def normalize_plate(plate: str) -> str:
    """Normalize whitespace, case and Latin/Cyrillic lookalikes."""
    compact = re.sub(r"\s+", "", plate.strip().upper())
    return compact.translate(CYRILLIC_PLATE_CHARS)


def _load_all() -> dict:
    if STATE_PATH.exists():
        try:
            return json.loads(STATE_PATH.read_text(encoding="utf-8"))
        except (json.JSONDecodeError, ValueError):
            corrupt = STATE_PATH.with_name(f"{STATE_PATH.name}.corrupt-{int(time.time())}")
            try:
                os.replace(STATE_PATH, corrupt)
            except OSError:
                pass
            return {"chats": {}}
    return {"chats": {}}


def _save_all(data: dict) -> None:
    atomic_write_json(STATE_PATH, data)


def _chat_state(data: dict, chat_id: str) -> dict:
    chats = data.setdefault("chats", {})
    return chats.setdefault(str(chat_id), {"run_counter": 0, "open_jobs": {}})


class OpenJobsTracker:
    """
    Usage per run:
        tracker = OpenJobsTracker(chat_id)
        tracker.start_run()                             # call once at the start
        tracker.register_new_job(plate, mid, excerpt)   # for each new_job_request message
        tracker.mark_closed(plate)                       # for each closed_job_report message
        due = tracker.jobs_due_for_reminder()            # after processing all messages
        tracker.save()                                   # persist at the end
    """

    def __init__(self, chat_id: str):
        self.chat_id = str(chat_id)
        with file_lock(STATE_PATH):
            self._data = _load_all()
        self._chat = _chat_state(self._data, self.chat_id)
        # Migrate legacy keys that may contain Latin lookalikes. This also
        # collapses duplicate Cyrillic/Latin variants into one tracked job.
        migrated: dict[str, dict] = {}
        for old_key, job in self._chat["open_jobs"].items():
            key = normalize_plate(str(job.get("plate") or old_key))
            if not key:
                key = normalize_plate(str(old_key)) or str(old_key)
            migrated.setdefault(key, job)
            migrated[key]["plate"] = key
        self._chat["open_jobs"] = migrated
        self._original_open_jobs = deepcopy(migrated)
        self.current_run = self._chat["run_counter"]  # set properly in start_run()

    def start_run(self) -> None:
        self._chat["run_counter"] += 1
        self.current_run = self._chat["run_counter"]

    def register_new_job(self, plate: str, mid: str = "", excerpt: str = "") -> None:
        key = normalize_plate(plate)
        if not key:
            return
        if key not in self._chat["open_jobs"]:
            self._chat["open_jobs"][key] = {
                "plate": plate,
                "mid": mid,
                "first_seen_run": self.current_run,
                "excerpt": excerpt[:200],
            }

    def mark_closed(self, plate: str) -> None:
        key = normalize_plate(plate)
        if not key:
            return
        for existing_key, job in list(self._chat["open_jobs"].items()):
            job_key = normalize_plate(str(job.get("plate") or existing_key))
            if normalize_plate(str(existing_key)) == key or job_key == key:
                self._chat["open_jobs"].pop(existing_key, None)

    def list_open_jobs(self) -> list[dict]:
        """All currently tracked jobs, regardless of grace period."""
        return list(self._chat["open_jobs"].values())

    def clear_all(self) -> None:
        """Remove all tracked jobs for this chat before a full rebuild."""
        self._chat["open_jobs"] = {}

    def jobs_due_for_reminder(self) -> list[dict]:
        """
        Jobs first seen in an EARLIER run than this one — i.e. they've
        survived at least one full check cycle without being closed.
        A job registered in THIS run is never due yet (grace period).
        """
        return [
            job for job in self._chat["open_jobs"].values()
            if job["first_seen_run"] < self.current_run
        ]

    def save(self) -> None:
        with file_lock(STATE_PATH):
            latest = _load_all()
            latest_chat = _chat_state(latest, self.chat_id)
            original_keys = set(self._original_open_jobs)
            current_keys = set(self._chat["open_jobs"])
            for removed in original_keys - current_keys:
                latest_chat["open_jobs"].pop(removed, None)
            for key in current_keys:
                if key not in original_keys or self._chat["open_jobs"][key] != self._original_open_jobs.get(key):
                    latest_chat["open_jobs"][key] = self._chat["open_jobs"][key]
            latest_chat["run_counter"] = max(
                int(latest_chat.get("run_counter", 0)), int(self._chat.get("run_counter", 0))
            )
            _save_all(latest)
            self._data = latest
            self._chat = latest_chat
            self._original_open_jobs = deepcopy(latest_chat["open_jobs"])
