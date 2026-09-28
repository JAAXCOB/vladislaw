"""Reliable operational MAX -> AV Rescue synchronization.

This stream is deliberately independent from both Excel reports. It sends no
price, salary or financial fields. Failed deliveries are kept in a small local
queue and retried on the next MAX event or daily reconciliation run.
"""
from __future__ import annotations

import json
import logging
import re
import time
from pathlib import Path
from typing import Any

import httpx

from webhook.config import settings
from webhook.reporting_rules import normalize_plate
from webhook.schema import ExtractedJob
from webhook.storage import atomic_write_json, file_lock

log = logging.getLogger("max_webhook.av_rescue")
MAX_DELIVERY_ATTEMPTS = 10
MAX_RETRY_DELAY_SECONDS = 3600


def _split_destination_field(original_text: str, fallback: str | None) -> tuple[str | None, str | None]:
    """Split `Куда: СЕРВИС (АДРЕС)` into a service title and point B."""
    labelled = re.search(r"(?im)^\\s*Куда\\s*:\\s*(.+?)\\s*$", original_text)
    if not labelled:
        return None, fallback

    raw_value = labelled.group(1).strip()
    structured = re.match(r"^(.+?)\\s*\\(([^()]*)\\)\\s*$", raw_value)
    if not structured:
        return None, fallback or raw_value

    service_name = structured.group(1).strip()
    destination = structured.group(2).strip()
    if not service_name or not destination:
        return None, fallback or raw_value
    return service_name, destination


def _queue_path() -> Path:
    path = Path(settings.av_rescue_sync_queue_path)
    if not path.is_absolute():
        path = Path(__file__).resolve().parent.parent / path
    return path


def _read_queue(path: Path | None = None) -> list[dict[str, Any]]:
    path = path or _queue_path()
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, list):
            raise ValueError("queue root must be a list")
        normalized = []
        for item in data:
            if not isinstance(item, dict):
                continue
            if "payload" in item:
                normalized.append(item)
            else:  # Migrate the original payload-only queue format.
                normalized.append({"payload": item, "attempts": 0, "next_attempt_at": 0})
        return normalized
    except Exception:
        log.exception("Cannot read AV Rescue retry queue")
        raise


def pending_sync_count() -> int:
    """Number of partner events waiting for a retry (used by /health)."""
    path = _queue_path()
    with file_lock(path):
        try:
            return len(_read_queue(path))
        except Exception:
            return -1


def _write_queue(items: list[dict[str, Any]], path: Path | None = None) -> None:
    atomic_write_json(path or _queue_path(), items)


def _dead_queue_path() -> Path:
    path = _queue_path()
    return path.with_name(path.stem + ".dead" + path.suffix)


def _payload_key(payload: dict[str, Any]) -> tuple[Any, Any]:
    return payload.get("source_id"), payload.get("event")


def _failure_record(payload: dict[str, Any], previous: dict[str, Any] | None = None) -> dict[str, Any]:
    attempts = int((previous or {}).get("attempts", 0)) + 1
    delay = min(MAX_RETRY_DELAY_SECONDS, 15 * (2 ** min(attempts - 1, 8)))
    return {
        "payload": payload,
        "attempts": attempts,
        "next_attempt_at": int(time.time()) + delay,
    }


def _post(payload: dict[str, Any]) -> bool:
    if not settings.av_rescue_api_url or not settings.av_rescue_api_key:
        log.debug("AV Rescue integration is not configured")
        return True
    try:
        with httpx.Client(timeout=15) as client:
            response = client.post(
                settings.av_rescue_api_url,
                headers={"X-AVR-Partner-Key": settings.av_rescue_api_key},
                json=payload,
            )
        if response.status_code == 200 and response.json().get("ok") is True:
            log.info("AV Rescue sync delivered | event=%s", payload.get("event", "unknown"))
            return True
        log.error("AV Rescue sync failed: HTTP %s", response.status_code)
    except Exception:
        log.exception("AV Rescue sync request failed")
    return False


def _deliver_with_retry(payload: dict[str, Any]) -> None:
    """Try the current payload and a small due batch without blocking on the full queue."""
    path = _queue_path()
    with file_lock(path):
        pending = _read_queue(path) if path.exists() else []
        key = _payload_key(payload)
        previous = next(
            (item for item in pending if _payload_key(item.get("payload", {})) == key), None
        )
        pending = [item for item in pending if _payload_key(item.get("payload", {})) != key]

        if not _post(payload):
            failed = _failure_record(payload, previous)
            if failed["attempts"] >= MAX_DELIVERY_ATTEMPTS:
                dead_path = _dead_queue_path()
                dead = _read_queue(dead_path) if dead_path.exists() else []
                dead.append(failed)
                _write_queue(dead, dead_path)
            else:
                pending.append(failed)

        now = int(time.time())
        due = [item for item in pending if int(item.get("next_attempt_at", 0)) <= now][:2]
        for item in due:
            pending.remove(item)
            queued_payload = item.get("payload", {})
            if _post(queued_payload):
                continue
            failed = _failure_record(queued_payload, item)
            if failed["attempts"] >= MAX_DELIVERY_ATTEMPTS:
                dead_path = _dead_queue_path()
                dead = _read_queue(dead_path) if dead_path.exists() else []
                dead.append(failed)
                _write_queue(dead, dead_path)
            else:
                pending.append(failed)
        _write_queue(pending, path)


def sync_extracted_job(
    job: ExtractedJob,
    chat_id: int | str | None,
    message_id: str | None,
    original_text: str,
) -> None:
    """Send a new or closed partner job to AV Rescue using a stable MAX ID."""
    if not message_id or not (job.is_new_job_request or job.is_closed_job_report):
        return

    source_id = f"max:{chat_id or 'unknown'}:{message_id}"
    destination_service, destination = _split_destination_field(original_text, job.destination)
    service = destination_service or (job.services[0].name if job.services else "Эвакуация")
    vehicle = " ".join(part for part in (job.vehicle_make, job.vehicle_model) if part).strip()
    payload: dict[str, Any] = {
        "event": "close" if job.is_closed_job_report else "upsert",
        "source_id": source_id,
        "source_chat_id": str(chat_id or ""),
        "source_message_id": message_id,
        "license_plate": job.license_plate,
        "service": service,
        "vehicle": vehicle or None,
        "pickup_address": job.pickup_address,
        "pickup_lat": job.pickup_lat,
        "pickup_lng": job.pickup_lng,
        "destination": destination,
        "destination_lat": job.destination_lat,
        "destination_lng": job.destination_lng,
        "service_until": job.service_until,
        "phone": job.customer_phone,
        "comment": job.customer_comment or original_text[:600],
    }
    _deliver_with_retry(payload)


def sync_explicit_close(
    chat_id: int | str | None,
    message_id: str | None,
    original_text: str,
) -> None:
    """Synchronize a deterministic close report used by the batch importer."""
    if not message_id:
        return
    plate = normalize_plate(original_text)
    if not plate:
        log.warning("Explicit close sync skipped: no license plate | mid=%s", message_id)
        return
    _deliver_with_retry(
        {
            "event": "close",
            "source_id": f"max:{chat_id or 'unknown'}:{message_id}",
            "source_chat_id": str(chat_id or ""),
            "source_message_id": message_id,
            "license_plate": plate,
            "comment": original_text[:600],
        }
    )
