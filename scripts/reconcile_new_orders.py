"""Recover MAX new-order events missed by webhook delivery.

This job is deliberately isolated from Excel, payroll and close reports.  It
reads a rolling MAX history window and replays only messages classified as new
orders.  The existing partner endpoint receives the same stable source ID used
by the webhook (``max:<chat_id>:<mid>``), so retries update the same order
instead of creating a second one.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import time
from pathlib import Path

import httpx
from dotenv import load_dotenv

load_dotenv()
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from webhook.av_rescue_client import sync_extracted_job
from webhook.config import settings
from webhook.extractor import extract_job
from webhook.models import Message
from webhook.reporting_rules import is_bot_generated_message, normalize_plate
from webhook.storage import atomic_write_json, locked_target

STATE_PATH = Path(__file__).resolve().parent.parent / "data" / "new_order_reconcile_state.json"
RUN_LOCK = STATE_PATH.with_name("new_order_reconcile.run")
PAGE_SIZE = 100
MAX_STATE_ITEMS = 5000


def _fingerprint(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _load_state(path: Path = STATE_PATH) -> dict[str, str]:
    if not path.exists():
        return {}
    raw = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise ValueError("reconciliation state must be an object")
    return {str(mid): str(value) for mid, value in raw.items()}


def _save_state(state: dict[str, str], path: Path = STATE_PATH) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    kept = dict(list(state.items())[-MAX_STATE_ITEMS:])
    atomic_write_json(path, kept)


def _normalize_requested_plate(value: str) -> str:
    normalized = normalize_plate(value)
    if not normalized:
        raise ValueError(f"invalid plate: {value!r}")
    return normalized


def fetch_messages(
    client: httpx.Client,
    chat_id: str,
    oldest_ms: int,
    newest_ms: int,
) -> list[dict]:
    messages: list[dict] = []
    window_end = newest_ms
    while True:
        response = client.get(
            f"{settings.MAX_API_BASE}/messages",
            headers={"Authorization": settings.max_bot_token},
            params={
                "chat_id": chat_id,
                "from": window_end,
                "to": oldest_ms,
                "count": PAGE_SIZE,
            },
        )
        response.raise_for_status()
        batch = response.json().get("messages") or []
        if not batch:
            break
        messages.extend(batch)
        if len(batch) < PAGE_SIZE:
            break
        minimum = min(int(item.get("timestamp") or 0) for item in batch)
        if minimum >= window_end or minimum <= oldest_ms:
            break
        window_end = minimum - 1
    messages.sort(key=lambda item: int(item.get("timestamp") or 0))
    return messages


def reconcile_messages(
    raw_messages: list[dict],
    chat_id: str,
    state: dict[str, str],
    *,
    only_plates: set[str] | None = None,
    bootstrap: bool = False,
    dry_run: bool = False,
) -> dict[str, int]:
    counts = {"scanned": 0, "unchanged": 0, "new_orders": 0, "replayed": 0, "ignored": 0}
    for raw in raw_messages:
        message = Message.model_validate(raw)
        mid = message.body.mid if message.body else None
        text = message.resolve_text() or ""
        if not mid or not text:
            counts["ignored"] += 1
            continue
        if is_bot_generated_message(text, bool(message.sender and message.sender.is_bot)):
            counts["ignored"] += 1
            continue

        counts["scanned"] += 1
        fingerprint = _fingerprint(text)
        if state.get(mid) == fingerprint and not only_plates:
            counts["unchanged"] += 1
            continue

        if only_plates:
            plate = normalize_plate(text)
            if plate not in only_plates:
                continue

        if bootstrap:
            state[mid] = fingerprint
            continue

        sender_name = message.sender.first_name if message.sender else ""
        job = extract_job(text, sender_name)
        if job.is_new_job_request:
            counts["new_orders"] += 1
            if not dry_run:
                sync_extracted_job(job, chat_id, mid, text)
                counts["replayed"] += 1
        else:
            counts["ignored"] += 1
        if not dry_run:
            state[mid] = fingerprint
    return counts


@locked_target(RUN_LOCK)
def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--hours", type=int, default=6)
    parser.add_argument("--only-plate", action="append", default=[])
    parser.add_argument(
        "--bootstrap",
        action="store_true",
        help="Record the current window without synchronizing it",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Classify matching messages without synchronizing or changing state",
    )
    args = parser.parse_args()
    if not 1 <= args.hours <= 168:
        parser.error("--hours must be between 1 and 168")
    if args.bootstrap and args.only_plate:
        parser.error("--bootstrap cannot be combined with --only-plate")
    if args.bootstrap and args.dry_run:
        parser.error("--bootstrap cannot be combined with --dry-run")

    chat_ids = sorted(settings.allowed_chat_ids)
    requested = {_normalize_requested_plate(value) for value in args.only_plate}
    state = _load_state()
    now_ms = int(time.time() * 1000)
    oldest_ms = now_ms - args.hours * 3600 * 1000
    summary = {"scanned": 0, "unchanged": 0, "new_orders": 0, "replayed": 0, "ignored": 0}

    with httpx.Client(
        timeout=30,
        verify="/etc/ssl/certs/ca-certificates.crt",
        follow_redirects=False,
    ) as client:
        for chat_id in chat_ids:
            messages = fetch_messages(client, chat_id, oldest_ms, now_ms)
            result = reconcile_messages(
                messages,
                chat_id,
                state,
                only_plates=requested or None,
                bootstrap=args.bootstrap,
                dry_run=args.dry_run,
            )
            for key, value in result.items():
                summary[key] += value

    if not args.dry_run:
        _save_state(state)
    print(json.dumps(summary, ensure_ascii=False, sort_keys=True))
    completed = summary["new_orders"] if args.dry_run else summary["replayed"]
    if requested and completed != len(requested):
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
