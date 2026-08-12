"""
Periodic batch import — no server needed.

Every run fetches a fixed rolling window (default: the last 24 hours)
from the MAX group chat via GET /messages, runs AI extraction, and
appends results to the Excel file. The window does NOT extend from the
previous run — each run only ever looks at "now minus N hours". Safe
duplicate handling comes from tracking already-processed message IDs
(data/import_state.json), not from the window itself, so a message
seen in two overlapping windows is still only written once.

If a scheduled run is ever missed (computer off, etc.), anything older
than the window is simply not picked up — this is a deliberate
trade-off for predictable, bounded runs rather than an ever-growing
catch-up window.

When ENABLE_JOB_REMINDERS=true, this also tracks "new job request"
messages (license plate) until a matching "closed job" message shows
up for the same plate. Anything still open after at least one full
run has passed since it was first seen gets a reminder posted back
into the chat — every run, until it's closed. A job first seen in
THIS run is never reminded in this same run (one cycle of grace).

Run on a schedule with cron/launchd/Task Scheduler (e.g. 3x/day).

Usage:
    python scripts/daily_import.py
    python scripts/daily_import.py --hours 48   # look back further than usual
"""
import argparse
import json
import sys
import textwrap
import time
from pathlib import Path

import httpx
from dotenv import load_dotenv

# Windows redirects stdout to a non-UTF-8 code page (e.g. cp1251) when it's
# not a real console (as happens under Task Scheduler with `>> log.txt`),
# which crashes on emoji/unusual characters in chat messages. Force UTF-8
# so nothing in the log can bring the whole run down mid-batch.
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

load_dotenv()

sys.path.insert(0, str(Path(__file__).parent.parent))

from webhook.config import settings
from webhook.excel_writer import append_job
from webhook.extractor import extract_job
from webhook.max_client import send_message
from webhook.models import Message
from webhook.open_jobs_tracker import OpenJobsTracker
from webhook.payroll_writer import append_salary_row

STATE_PATH = Path(__file__).parent.parent / "data" / "import_state.json"
MAX_PROCESSED_MIDS = 2000  # rolling window to guard against boundary duplicates
PAGE_SIZE = 100  # MAX API max for GET /messages


def load_state() -> dict:
    if STATE_PATH.exists():
        return json.loads(STATE_PATH.read_text())
    return {"processed_mids": []}


def save_state(state: dict) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    # Keep only the most recent mids to avoid unbounded growth
    state["processed_mids"] = state["processed_mids"][-MAX_PROCESSED_MIDS:]
    STATE_PATH.write_text(json.dumps(state, ensure_ascii=False, indent=2))


def fetch_all_messages(client: httpx.Client, chat_id: str, oldest_ms: int, newest_ms: int) -> list[dict]:
    """
    Paginate through GET /messages until the full [oldest_ms, newest_ms] window is covered.

    MAX returns messages newest-first, and per the API's own description:
    "Messages traversed in reverse direction ... if you use `from` and `to`
    parameters, `to` must be less than `from`". So `from` is the upper
    (more recent) bound and `to` is the lower (older) bound — the opposite
    of what the names might suggest.
    """
    all_messages: list[dict] = []
    window_end = newest_ms  # this is the "from" query param — moves backward each page

    while True:
        resp = client.get(
            f"{settings.MAX_API_BASE}/messages",
            headers={"Authorization": settings.max_bot_token},
            params={
                "chat_id": chat_id,
                "from": window_end,
                "to": oldest_ms,
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

        # Move the window backward past the oldest message in this page
        min_ts = min(m.get("timestamp", 0) for m in batch)
        if min_ts >= window_end:
            break
        window_end = min_ts - 1
        if window_end <= oldest_ms:
            break

    # MAX returns newest-first; process oldest-to-newest so Excel rows land
    # in chronological order like the rest of the sheet.
    all_messages.sort(key=lambda m: m.get("timestamp", 0))
    return all_messages


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--hours", type=int, default=24, help="Rolling look-back window, every run (default: 24)")
    args = parser.parse_args()

    if not settings.max_chat_id:
        sys.exit("ERROR: MAX_CHAT_ID is not set in .env")
    if not settings.excel_file_path:
        sys.exit("ERROR: EXCEL_FILE_PATH is not set in .env")

    state = load_state()
    now_ms = int(time.time() * 1000)
    from_ms = now_ms - args.hours * 3600 * 1000

    print(f"Fetching messages from {chat_id_label(settings.max_chat_id)} "
          f"— last {args.hours}h ({from_ms} to {now_ms})...\n")

    processed_mids = set(state["processed_mids"])

    tracker: OpenJobsTracker | None = None
    if settings.enable_job_reminders:
        tracker = OpenJobsTracker(settings.max_chat_id)
        tracker.start_run()

    # verify=False: platform-api2.max.ru uses a Russian government CA (Минцифры)
    # not included in standard CA bundles.
    with httpx.Client(timeout=30, verify=False) as client:
        messages = fetch_all_messages(client, settings.max_chat_id, from_ms, now_ms)

    print(f"Received {len(messages)} message(s) from MAX.\n")

    new_count = 0
    review_count = 0
    skipped_count = 0
    new_job_count = 0
    payroll_written = 0
    payroll_unmatched = 0

    for raw_msg in messages:
        message = Message.model_validate(raw_msg)
        mid = message.body.mid if message.body else None
        text = message.resolve_text()  # falls back to link.message.text for forwards
        ts = message.timestamp or 0
        sender_name = message.sender.first_name if message.sender else ""
        employee_name = message.effective_sender_name()  # forwarded original sender if applicable

        if not mid:
            continue
        if mid in processed_mids:
            continue
        if not text:
            print(f"--- {sender_name}: (нет текста — фото/видео без подписи), пропущено")
            skipped_count += 1
            processed_mids.add(mid)
            continue

        print(f"--- {sender_name}: {text!r}")
        try:
            job = extract_job(text, sender_name)

            if job.is_new_job_request:
                if tracker and job.license_plate:
                    tracker.register_new_job(job.license_plate, text)
                    print(f"    -> новая заявка, отслеживаем номер {job.license_plate}")
                else:
                    print("    -> новая заявка (номер не найден или напоминания выключены)")
                new_job_count += 1
                processed_mids.add(mid)
                continue

            if not job.is_closed_job_report:
                print("    -> заявка не закрыта / не по теме, пропущено")
                skipped_count += 1
                processed_mids.add(mid)
                continue

            if tracker and job.license_plate:
                tracker.mark_closed(job.license_plate)

            sheet = append_job(settings.excel_file_path, job, ts, text)
            new_count += 1
            if job.needs_review:
                review_count += 1
                print(f"    -> записано в '{sheet}', ТРЕБУЕТ ПРОВЕРКИ: {job.review_reason}")
            else:
                print(f"    -> записано в '{sheet}'")

            if settings.payroll_file_path:
                try:
                    payroll_sheet, matched = append_salary_row(
                        settings.payroll_file_path, job, ts, employee_name, text
                    )
                    if matched:
                        payroll_written += 1
                        print(f"    -> зарплата: '{payroll_sheet}', сотрудник={employee_name}")
                    else:
                        payroll_unmatched += 1
                        print(f"    -> зарплата: строка в '{payroll_sheet}' добавлена, "
                              f"но сотрудник '{employee_name}' не распознан — впишите вручную")
                except Exception as exc:
                    print(f"    -> ОШИБКА зарплатного файла: {exc}")
        except Exception as exc:
            print(f"    -> ОШИБКА: {exc}")
            continue

        processed_mids.add(mid)

    state["processed_mids"] = list(processed_mids)
    save_state(state)

    reminders_sent = 0
    if tracker:
        due = tracker.jobs_due_for_reminder()
        if due:
            print(f"\n--- Напоминания о незакрытых заявках ({len(due)}) ---")
        for open_job in due:
            plate = open_job["plate"]
            # Collapse the raw multi-line message into one line and cut at a
            # word boundary (not mid-word) for a clean, compact reminder.
            raw_excerpt = " ".join(open_job.get("excerpt", "").split())
            excerpt = textwrap.shorten(raw_excerpt, width=100, placeholder="...")
            reminder_text = (
                f"⚠️ Заявка {plate} всё ещё не закрыта. Не забудьте отчитаться о выполнении!\n"
                f"{excerpt}"
            )
            try:
                send_message(settings.max_chat_id, reminder_text, settings.max_bot_token, settings.MAX_API_BASE)
                reminders_sent += 1
                print(f"    -> напоминание отправлено: {plate}")
            except Exception as exc:
                print(f"    -> ОШИБКА отправки напоминания для {plate}: {exc}")
        tracker.save()

    summary = (
        f"\nГотово. Новых записей: {new_count} (из них требуют проверки: {review_count}), "
        f"новых заявок в работу: {new_job_count}, "
        f"пропущено нерабочих сообщений: {skipped_count}."
    )
    if settings.payroll_file_path:
        summary += f" Зарплата: записано {payroll_written}, не распознан сотрудник у {payroll_unmatched}."
    if settings.enable_job_reminders:
        summary += f" Напоминаний отправлено: {reminders_sent}."
    print(summary)


def chat_id_label(chat_id: str) -> str:
    return f"chat_id={chat_id}"


if __name__ == "__main__":
    main()
