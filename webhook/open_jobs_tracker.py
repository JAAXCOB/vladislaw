"""
Tracks license plates from "new job request" messages until a matching
"closed job" message appears for the same plate, so the caller can remind
about anything still open after at least one full check cycle has passed.

State is scoped per chat_id (data/open_jobs_state.json), so testing in a
test group can never mix with production tracking.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Optional

STATE_PATH = Path(__file__).parent.parent / "data" / "open_jobs_state.json"


def normalize_plate(plate: str) -> str:
    """Uppercase, strip all whitespace — defensive re-normalization in
    case the model formats a plate slightly differently across two
    separate extraction calls (new-job message vs closed-job message)."""
    return re.sub(r"\s+", "", plate.strip().upper())


def _load_all() -> dict:
    if STATE_PATH.exists():
        return json.loads(STATE_PATH.read_text())
    return {"chats": {}}


def _save_all(data: dict) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    STATE_PATH.write_text(json.dumps(data, ensure_ascii=False, indent=2))


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
        self._data = _load_all()
        self._chat = _chat_state(self._data, self.chat_id)
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
        self._chat["open_jobs"].pop(key, None)

    def list_open_jobs(self) -> list[dict]:
        """All currently tracked jobs, regardless of grace period."""
        return list(self._chat["open_jobs"].values())

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
        _save_all(self._data)
