"""Remove tracked reminders that have an explicit close report in MAX history."""
from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

import httpx
from dotenv import load_dotenv

load_dotenv()
sys.path.insert(0, str(Path(__file__).parent.parent))

from scripts.daily_import import fetch_all_messages
from webhook.config import settings
from webhook.models import Message
from webhook.open_jobs_tracker import OpenJobsTracker, normalize_plate
from webhook.reporting_rules import is_bot_generated_message, parse_explicit_closed_report


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--hours", type=int, default=720)
    args = parser.parse_args()

    if not settings.max_chat_id:
        sys.exit("ERROR: MAX_CHAT_ID is not set in .env")

    now_ms = int(time.time() * 1000)
    oldest_ms = now_ms - max(1, args.hours) * 3600 * 1000
    with httpx.Client(timeout=30, verify="/etc/ssl/certs/ca-certificates.crt") as client:
        messages = fetch_all_messages(client, settings.max_chat_id, oldest_ms, now_ms)

    tracker = OpenJobsTracker(settings.max_chat_id)
    before = {
        normalize_plate(str(job.get("plate") or ""))
        for job in tracker.list_open_jobs()
    }
    closed: set[str] = set()

    for raw in messages:
        message = Message.model_validate(raw)
        text = message.resolve_text() or ""
        if not text or is_bot_generated_message(
            text, bool(message.sender and message.sender.is_bot)
        ):
            continue
        job = parse_explicit_closed_report(text)
        if job and job.license_plate:
            closed.add(normalize_plate(job.license_plate))

    for plate in closed:
        tracker.mark_closed(plate)
    tracker.save()

    after = {
        normalize_plate(str(job.get("plate") or ""))
        for job in tracker.list_open_jobs()
    }
    print(
        "reconcile_open_jobs: "
        f"scanned={len(messages)} explicit_closed={len(closed)} "
        f"removed={len(before - after)} remaining={len(after)}"
    )


if __name__ == "__main__":
    main()
