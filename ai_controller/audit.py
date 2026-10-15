from __future__ import annotations

import hashlib
import hmac
import json
import os
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class AuditLog:
    """Append-only, HMAC chained JSONL audit without message bodies or secrets."""

    def __init__(self, path: Path, key: str) -> None:
        if len(key) < 32:
            raise ValueError("audit key is too short")
        self.path = path
        self.key = key.encode("utf-8")
        self._lock = threading.Lock()
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)

    def _last_hash(self) -> str:
        if not self.path.exists() or self.path.stat().st_size == 0:
            return "0" * 64
        line = b""
        with self.path.open("rb") as handle:
            for candidate in handle:
                if candidate.strip():
                    line = candidate.strip()
        try:
            return str(json.loads(line).get("hash", "0" * 64))
        except Exception as exc:
            raise RuntimeError("audit chain tail is unreadable") from exc

    def append(self, event: str, actor: str, outcome: str, details: dict[str, Any]) -> str:
        safe_details = {
            key: value
            for key, value in details.items()
            if key not in {"token", "password", "body", "text", "email", "phone", "subject"}
        }
        with self._lock:
            previous = self._last_hash()
            record = {
                "timestamp": datetime.now(timezone.utc).isoformat(),
                "event": event,
                "actor": actor,
                "outcome": outcome,
                "details": safe_details,
                "previous_hash": previous,
            }
            canonical = json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
            digest = hmac.new(self.key, canonical.encode("utf-8"), hashlib.sha256).hexdigest()
            record["hash"] = digest
            encoded = (json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n").encode("utf-8")
            descriptor = os.open(self.path, os.O_WRONLY | os.O_APPEND | os.O_CREAT, 0o600)
            try:
                os.write(descriptor, encoded)
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
            return digest

    def verify(self) -> tuple[bool, int]:
        previous = "0" * 64
        count = 0
        if not self.path.exists():
            return True, 0
        with self.path.open("r", encoding="utf-8") as handle:
            for line in handle:
                if not line.strip():
                    continue
                record = json.loads(line)
                digest = record.pop("hash")
                if record.get("previous_hash") != previous:
                    return False, count
                canonical = json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
                expected = hmac.new(self.key, canonical.encode("utf-8"), hashlib.sha256).hexdigest()
                if not hmac.compare_digest(digest, expected):
                    return False, count
                previous = digest
                count += 1
        return True, count
