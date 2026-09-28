"""Durable, idempotent local inbox for authenticated MAX webhook events."""
from __future__ import annotations

import hashlib
import json
import logging
import os
import threading
import time
from pathlib import Path
from typing import Any, Callable

from webhook.config import settings
from webhook.storage import atomic_write_json, file_lock, fsync_directory

log = logging.getLogger("max_webhook.inbox")
_stop_event = threading.Event()
_worker: threading.Thread | None = None


def _root() -> Path:
    path = Path(settings.event_inbox_path)
    if not path.is_absolute():
        path = Path(__file__).resolve().parent.parent / path
    return path


def _directory(name: str) -> Path:
    path = _root() / name
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    try:
        os.chmod(path, 0o700)
    except OSError:
        pass
    return path


def event_id(payload: dict[str, Any]) -> str:
    canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def enqueue(payload: dict[str, Any]) -> tuple[str, bool]:
    """Persist an event before acknowledging it; exact replays are idempotent."""
    identifier = event_id(payload)
    filename = identifier + ".json"
    with file_lock(_root() / "enqueue.lock"):
        for state in ("pending", "processing", "done", "dead"):
            if (_directory(state) / filename).exists():
                return identifier, False
        destination = _directory("pending") / filename
        record = {
            "event_id": identifier,
            "payload": payload,
            "attempts": 0,
            "next_attempt_at": 0,
        }
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
        try:
            descriptor = os.open(destination, flags, 0o600)
        except FileExistsError:
            return identifier, False
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(record, handle, ensure_ascii=False)
            handle.flush()
            os.fsync(handle.fileno())
        fsync_directory(destination.parent)
    return identifier, True


def pending_count() -> int:
    return sum(1 for state in ("pending", "processing") for _ in _directory(state).glob("*.json"))


def _retry_record(record: dict[str, Any]) -> dict[str, Any]:
    attempts = int(record.get("attempts", 0)) + 1
    delay = settings.event_retry_base_seconds * (2 ** min(attempts - 1, 8))
    return {
        **record,
        "attempts": attempts,
        "next_attempt_at": int(time.time()) + min(delay, 3600),
    }


def drain(processor: Callable[[dict[str, Any]], None], max_items: int = 10) -> int:
    """Process due events; failed events are retried or moved to dead letter."""
    processed = 0
    lock_path = _root() / "drain.lock"
    with file_lock(lock_path):
        pending = _directory("pending")
        processing = _directory("processing")
        # A file left here means the prior process died before committing a result.
        for orphan in processing.glob("*.json"):
            os.replace(orphan, pending / orphan.name)

        now = int(time.time())
        for source in sorted(pending.glob("*.json")):
            if processed >= max_items:
                break
            try:
                record = json.loads(source.read_text(encoding="utf-8"))
            except Exception:
                log.exception("Inbox record is unreadable | file=%s", source.name)
                os.replace(source, _directory("dead") / source.name)
                continue
            if int(record.get("next_attempt_at", 0)) > now:
                continue
            claimed = processing / source.name
            os.replace(source, claimed)
            try:
                processor(record["payload"])
            except Exception as exc:
                failed = _retry_record(record)
                log.warning(
                    "Inbox processing failed | event_id=%s | attempt=%d | error=%s",
                    record.get("event_id", "unknown")[:12],
                    failed["attempts"],
                    type(exc).__name__,
                )
                if failed["attempts"] >= settings.event_max_attempts:
                    atomic_write_json(_directory("dead") / source.name, failed)
                else:
                    atomic_write_json(pending / source.name, failed)
                claimed.unlink(missing_ok=True)
            else:
                atomic_write_json(
                    _directory("done") / source.name,
                    {"event_id": record["event_id"], "completed_at": int(time.time())},
                )
                claimed.unlink(missing_ok=True)
            processed += 1
    return processed


def start_worker(processor: Callable[[dict[str, Any]], None]) -> None:
    global _worker
    if _worker and _worker.is_alive():
        return
    _stop_event.clear()

    def run() -> None:
        while not _stop_event.is_set():
            try:
                drain(processor)
            except Exception:
                log.exception("Inbox worker cycle failed")
            _stop_event.wait(settings.event_poll_seconds)

    _worker = threading.Thread(target=run, name="max-event-inbox", daemon=True)
    _worker.start()


def stop_worker() -> None:
    _stop_event.set()
    if _worker and _worker.is_alive():
        _worker.join(timeout=max(1, settings.event_poll_seconds + 1))
