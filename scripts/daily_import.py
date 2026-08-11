"""
Daily batch import — no server needed.

Fetches all messages from the MAX group chat since the last run
(via GET /messages, which returns chat history — not just live events),
runs AI extraction on each, and appends results to the Excel file.

Run manually once a day, or schedule with cron/launchd/Task Scheduler.

Usage:
    python scripts/daily_import.py
    python scripts/daily_import.py --hours 48   # look back further than usual
"""
import argparse
import json
import sys
import time
from pathlib import Path

import httpx
from dotenv import load_dotenv

load_dotenv()

sys.path.insert(0, str(Path(__file__).parent.parent))

from webhook.config import settings
from webhook.excel_writer import append_job
from webhook.extractor import extract_job

STATE_PATH = Path(__file__).parent.parent / "data" / "import_state.json"
MAX_PROCESSED_MIDS = 2000  # rolling window to guard against boundary duplicates
PAGE_SIZE = 100  # MAX API max for GET /messages


def load_state() -> dict:
    if STATE_PATH.exists():
        return json.loads(STATE_PATH.read_text())
    return {"last_timestamp_ms": 0, "processed_mids": []}


def save_state(state: dict) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    # Keep only the most recent mids to avoid unbounded growth
    state["processed_mids"] = state["processed_mids"][-MAX_PROCESSED_MIDS:]
    STATE_PATH.write_text(json.dumps(state, ensure_ascii=False, indent=2))


def fetch_all_messages(client: httpx.Client, chat_id: str, from_ms: int, to_ms: int) -> list[dict]:
    """Paginate through GET /messages until the full [from_ms, to_ms) window is covered."""
    all_messages: list[dict] = []
    window_start = from_ms

    while True:
        resp = client.get(
            f"{settings.MAX_API_BASE}/messages",
            headers={"Authorization": settings.max_bot_token},
            params={
                "chat_id": chat_id,
                "from": window_start,
                "to": to_ms,
                "count": PAGE_SIZE,
            },
        )
        if resp.status_code != 200:
            raise RuntimeError(f"GET /messages failed: {resp.status_code} {resp.text[:300]}")

        batch = resp.json().get("messages", [])
        if not batch:
            break

        all_messages.extend(batch)

        if len(batch) < PAGE_SIZE:
            break

        # Advance the window past the last message received to avoid re-fetching it
        last_ts = max(m.get("timestamp", 0) for m in batch)
        if last_ts <= window_start:
            break
        window_start = last_ts + 1

    return all_messages


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--hours", type=int, default=24, help="Look-back window on first run (default: 24)")
    args = parser.parse_args()

    if not settings.max_chat_id:
        sys.exit("ERROR: MAX_CHAT_ID is not set in .env")
    if not settings.excel_file_path:
        sys.exit("ERROR: EXCEL_FILE_PATH is not set in .env")

    state = load_state()
    now_ms = int(time.time() * 1000)

    from_ms = state["last_timestamp_ms"] + 1
    if from_ms <= 1:
        from_ms = now_ms - args.hours * 3600 * 1000

    print(f"Fetching messages from {chat_id_label(settings.max_chat_id)} "
          f"between {from_ms} and {now_ms}...\n")

    processed_mids = set(state["processed_mids"])

    # verify=False: platform-api2.max.ru uses a Russian government CA (Минцифры)
    # not included in standard CA bundles.
    with httpx.Client(timeout=30, verify=False) as client:
        messages = fetch_all_messages(client, settings.max_chat_id, from_ms, now_ms)

    print(f"Received {len(messages)} message(s) from MAX.\n")

    new_count = 0
    review_count = 0
    max_ts_seen = state["last_timestamp_ms"]

    for msg in messages:
        body = msg.get("body") or {}
        mid = body.get("mid")
        text = body.get("text")
        ts = msg.get("timestamp", 0)
        sender = msg.get("sender") or {}
        sender_name = sender.get("first_name", "")

        max_ts_seen = max(max_ts_seen, ts)

        if not text or not mid:
            continue
        if mid in processed_mids:
            continue

        print(f"--- {sender_name}: {text!r}")
        try:
            job = extract_job(text, sender_name)
            sheet = append_job(settings.excel_file_path, job, ts, text)
            new_count += 1
            if job.needs_review:
                review_count += 1
                print(f"    -> записано в '{sheet}', ТРЕБУЕТ ПРОВЕРКИ: {job.review_reason}")
            else:
                print(f"    -> записано в '{sheet}'")
        except Exception as exc:
            print(f"    -> ОШИБКА: {exc}")
            continue

        processed_mids.add(mid)

    state["last_timestamp_ms"] = max_ts_seen
    state["processed_mids"] = list(processed_mids)
    save_state(state)

    print(f"\nГотово. Новых записей: {new_count}, из них требуют проверки: {review_count}.")


def chat_id_label(chat_id: str) -> str:
    return f"chat_id={chat_id}"


if __name__ == "__main__":
    main()
