"""Reliable operational MAX -> AV Rescue synchronization.

This stream is deliberately independent from both Excel reports. It sends no
price, salary or financial fields. Failed deliveries are kept in a small local
queue and retried on the next MAX event or daily reconciliation run.
"""
from __future__ import annotations

import json
import logging
import threading
from pathlib import Path
from typing import Any

import httpx

from webhook.config import settings
from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.av_rescue")
_queue_lock = threading.Lock()


def _queue_path() -> Path:
    path = Path(settings.av_rescue_sync_queue_path)
    if not path.is_absolute():
        path = Path(__file__).resolve().parent.parent / path
    return path


def _read_queue() -> list[dict[str, Any]]:
    path = _queue_path()
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, list) else []
    except Exception:
        log.exception("Cannot read AV Rescue retry queue")
        return []


def _write_queue(items: list[dict[str, Any]]) -> None:
    path = _queue_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(items[-500:], ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(path)


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
            return True
        log.error("AV Rescue sync failed: HTTP %s %s", response.status_code, response.text[:300])
    except Exception:
        log.exception("AV Rescue sync request failed")
    return False


def _deliver_with_retry(payload: dict[str, Any]) -> None:
    with _queue_lock:
        pending = _read_queue()
        remaining: list[dict[str, Any]] = []
        for queued in pending:
            if not _post(queued):
                remaining.append(queued)
        if not _post(payload):
            remaining = [
                queued for queued in remaining
                if not (
                    queued.get("source_id") == payload.get("source_id")
                    and queued.get("event") == payload.get("event")
                )
            ]
            remaining.append(payload)
        _write_queue(remaining)


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
    service = job.services[0].name if job.services else "Эвакуация"
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
        "destination": job.destination,
        "destination_lat": job.destination_lat,
        "destination_lng": job.destination_lng,
        "service_until": job.service_until,
        "comment": original_text[:600],
    }
    _deliver_with_retry(payload)
