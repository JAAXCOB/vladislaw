"""Close website cards accidentally created from MAX bot reminders."""
from __future__ import annotations

import argparse
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from scripts.repair_excel_since import fetch_messages, message_text
from webhook.av_rescue_client import _post
from webhook.config import settings
from webhook.reporting_rules import is_bot_generated_message


MOSCOW_TZ = timezone(timedelta(hours=3))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--since", default="2026-09-07")
    args = parser.parse_args()
    since = datetime.strptime(args.since, "%Y-%m-%d").replace(tzinfo=MOSCOW_TZ)
    messages = fetch_messages(int(since.timestamp() * 1000), int(time.time() * 1000))
    found = closed = failed = 0
    for item in messages:
        sender = item.get("sender") or {}
        text = message_text(item)
        if not is_bot_generated_message(text, bool(sender.get("is_bot"))):
            continue
        mid = str(((item.get("body") or {}).get("mid")) or "")
        chat_id = str(((item.get("recipient") or {}).get("chat_id")) or settings.max_chat_id or "unknown")
        if not mid:
            continue
        found += 1
        # Deliberately omit the plate. If a reminder card was never created,
        # the website must not fall back to closing the real job by plate.
        payload = {
            "event": "close",
            "source_id": f"max:{chat_id}:{mid}",
            "source_chat_id": chat_id,
            "source_message_id": mid,
            "license_plate": None,
        }
        if _post(payload):
            closed += 1
        else:
            failed += 1
    print(f"reminders_found={found} cleanup_delivered={closed} failures={failed}")
    if failed:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
