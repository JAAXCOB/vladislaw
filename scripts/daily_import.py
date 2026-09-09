"""
Periodic batch import — no server needed.

Every run fetches a fixed rolling window (default: the last 24 hours)
from the MAX group chat via GET /messages, runs AI extraction, and
appends results to the Excel file. The window does NOT extend from the
previous run.

Duplicate handling tracks both message IDs and the last text seen for each
message. Unchanged messages are skipped. If a previously processed MAX
message is edited, the same mid is processed again with its new text. This
is important for job tracking: a driver can correct an initially malformed
close report and the edited message will then close the tracked job.

When ENABLE_JOB_REMINDERS=true, this also tracks "new job request"
messages (license plate) until a matching "closed job" message shows
up for the same plate. Anything still open after at least one full
run has passed since it was first seen gets a reminder posted back
into the chat — every run, until it's closed. A job first seen in
THIS run is never reminded in this same run (one cycle of grace).
"""
import argparse
import hashlib
import json
import sys
import textwrap
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

import httpx
from dotenv import load_dotenv

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

load_dotenv()

sys.path.insert(0, str(Path(__file__).parent.parent))

from webhook.config import settings
from webhook.av_rescue_client import sync_extracted_job
from webhook.excel_writer import append_job
from webhook.extractor import extract_job
from webhook.max_client import send_message
from webhook.models import Message
from webhook.open_jobs_tracker import OpenJobsTracker
from webhook.payroll_writer import append_salary_row\nfrom webhook.reporting_rules import is_bot_generated_message

STATE_PATH = Path(__file__).parent.parent / "data" / "import_state.json"
MAX_PROCESSED_MIDS = 2000
PAGE_SIZE = 100
MOSCOW_TZ = timezone(timedelta(hours=3))


def load_state() -> dict:
    if STATE_PATH.exists():
        state = json.loads(STATE_PATH.read_text(encoding="utf-8"))
    else:
        state = {"processed_mids": []}
    state.setdefault("processed_mids", [])
    state.setdefault("message_fingerprints", {})
    return state


def save_state(state: dict) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    state["processed_mids"] = state["processed_mids"][-MAX_PROCESSED_MIDS:]
    kept = set(state["processed_mids"])
    state["message_fingerprints"] = {
        mid: fingerprint
        for mid, fingerprint in state.get("message_fingerprints", {}).items()
        if mid in kept
    }
    STATE_PATH.write_text(
        json.dumps(state, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def text_fingerprint(text: str) -> str:
    """Stable fingerprint used to detect edits while the MAX mid stays unchanged."""
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def fetch_all_messages(client: httpx.Client, chat_id: str, oldest_ms: int, newest_ms: int) -> list[dict]:
    all_messages: list[dict] = []
    window_end = newest_ms

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

        min_ts = min(m.get("timestamp", 0) for m in batch)
        if min_ts >= window_end:
            break
        window_end = min_ts - 1
        if window_end <= oldest_ms:
            break

    all_messages.sort(key=lambda m: m.get("timestamp", 0))
    return all_messages


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--hours", type=int, default=24, help="Rolling look-back window, every run (default: 24)")
    parser.add_argument("--since", type=str,
                        help="Process messages starting from this Moscow date, e.g. 2026-09-01")
    parser.add_argument("--payroll-only", action="store_true",
                        help="Only write payroll rows — skip main Excel, skip reminders, no messages sent to chat")
    parser.add_argument("--rebuild-open-jobs", action="store_true",
                        help="Clear and rebuild open jobs from the selected message window")
    args = parser.parse_args()
    payroll_only = args.payroll_only

    if payroll_only and args.rebuild_open_jobs:
        parser.error("--payroll-only and --rebuild-open-jobs cannot be used together")

    if not settings.max_chat_id:
        sys.exit("ERROR: MAX_CHAT_ID is not set in .env")
    if not settings.excel_file_path:
        sys.exit("ERROR: EXCEL_FILE_PATH is not set in .env")

    state = load_state()
    now_ms = int(time.time() * 1000)
    if args.since:
        try:
            since_dt = datetime.strptime(args.since, "%Y-%m-%d").replace(tzinfo=MOSCOW_TZ)
        except ValueError:
            parser.error("--since must use YYYY-MM-DD format, for example 2026-09-01")
        from_ms = int(since_dt.timestamp() * 1000)
        if from_ms > now_ms:
            parser.error("--since cannot be in the future")
        window_label = f"from {args.since} 00:00 Moscow time"
    else:
        from_ms = now_ms - args.hours * 3600 * 1000
        window_label = f"last {args.hours}h"

    print(f"Fetching messages from {chat_id_label(settings.max_chat_id)} "
          f"— {window_label} ({from_ms} to {now_ms})...\n")

    processed_mids = set(state["processed_mids"])
    message_fingerprints = state["message_fingerprints"]

    tracker: OpenJobsTracker | None = None
    if (settings.enable_job_reminders or args.rebuild_open_jobs) and not payroll_only:
        tracker = OpenJobsTracker(settings.max_chat_id)
        tracker.start_run()
        if args.rebuild_open_jobs:
            tracker.clear_all()
            print("Open-job list cleared; rebuilding it from the selected messages.\n")

    # Use the system CA store. The server has the Russian Trusted Root/Sub CA
    # required by platform-api2.max.ru installed in /etc/ssl/certs.
    with httpx.Client(timeout=30, verify="/etc/ssl/certs/ca-certificates.crt") as client:
        messages = fetch_all_messages(client, settings.max_chat_id, from_ms, now_ms)

    print(f"Received {len(messages)} message(s) from MAX.\n")

    new_count = 0
    review_count = 0
    skipped_count = 0
    new_job_count = 0
    payroll_written = 0
    payroll_unmatched = 0
    edited_count = 0

    for raw_msg in messages:
        message = Message.model_validate(raw_msg)
        mid = message.body.mid if message.body else None
        text = message.resolve_text()
        ts = message.timestamp or 0
        sender_name = message.sender.first_name if message.sender else ""
        employee_name = message.effective_sender_name()

        if not mid:
            continue

        if is_bot_generated_message(text or "", bool(message.sender and message.sender.is_bot)):
            processed_mids.add(mid)
            message_fingerprints[mid] = text_fingerprint(text or "")
            continue

        fingerprint = text_fingerprint(text or "")
        previously_processed = mid in processed_mids
        previous_fingerprint = message_fingerprints.get(mid)

        if not payroll_only and not args.rebuild_open_jobs and previously_processed:
            if previous_fingerprint == fingerprint:
                continue
            # Old state files have no fingerprints. Re-process each such message
            # once so edits made before this feature was deployed are not missed.
            edited_count += 1
            if previous_fingerprint is None:
                print(f"--- повторная проверка ранее обработанного сообщения {mid}")
            else:
                print(f"--- обнаружено редактирование сообщения {mid}")

        if not text:
            print(f"--- {sender_name}: (нет текста — фото/видео без подписи), пропущено")
            skipped_count += 1
            if not payroll_only:
                processed_mids.add(mid)
                message_fingerprints[mid] = fingerprint
            continue

        print(f"--- {sender_name}: {text!r}")
        try:
            job = extract_job(text, sender_name)

            if not payroll_only:
                sync_extracted_job(job, settings.max_chat_id, mid, text)

            if job.is_new_job_request:
                if not payroll_only:
                    if tracker and job.license_plate:
                        tracker.register_new_job(job.license_plate, mid, text)
                        print(f"    -> новая заявка, отслеживаем номер {job.license_plate}")
                    else:
                        print("    -> новая заявка (номер не найден или напоминания выключены)")
                else:
                    print("    -> новая заявка, пропущено (payroll-only режим)")
                new_job_count += 1
                if not payroll_only:
                    processed_mids.add(mid)
                    message_fingerprints[mid] = fingerprint
                continue

            if not job.is_closed_job_report:
                print("    -> заявка не закрыта / не по теме, пропущено")
                skipped_count += 1
                if not payroll_only:
                    processed_mids.add(mid)
                    message_fingerprints[mid] = fingerprint
                continue

            if tracker and job.license_plate:
                tracker.mark_closed(job.license_plate)

            if not payroll_only:
                sheet, inserted = append_job(settings.excel_file_path, job, ts, text)
                if inserted:
                    new_count += 1
                    if job.needs_review:
                        review_count += 1
                        print(f"    -> записано в '{sheet}', ТРЕБУЕТ ПРОВЕРКИ: {job.review_reason}")
                    else:
                        print(f"    -> записано в '{sheet}'")
                else:
                    print(f"    -> журнал: такая строка уже есть в '{sheet}', дубль пропущен")

            if settings.payroll_file_path:
                try:
                    payroll_sheet, matched, inserted = append_salary_row(
                        settings.payroll_file_path, job, ts, employee_name, text
                    )
                    if not inserted:
                        print(f"    -> зарплата: такая строка уже есть в '{payroll_sheet}', дубль пропущен")
                    elif matched:
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

        if not payroll_only:
            processed_mids.add(mid)
            message_fingerprints[mid] = fingerprint

    state["processed_mids"] = list(processed_mids)
    state["message_fingerprints"] = message_fingerprints
    save_state(state)

    reminders_sent = 0
    if tracker and not payroll_only:
        due = tracker.jobs_due_for_reminder()
        if due:
            print(f"\n--- Напоминания о незакрытых заявках ({len(due)}) ---")
        for open_job in due:
            plate = open_job["plate"]
            raw_excerpt = " ".join(open_job.get("excerpt", "").split())
            excerpt = textwrap.shorten(raw_excerpt, width=100, placeholder="...")
            reminder_text = (
                f"⚠️ Заявка {plate} всё ещё не закрыта. Не забудьте отчитаться о выполнении!\n"
                f"{excerpt}"
            )
            try:
                send_message(
                    settings.max_chat_id, reminder_text, settings.max_bot_token, settings.MAX_API_BASE,
                    reply_to_mid=open_job.get("mid") or None,
                )
                reminders_sent += 1
                print(f"    -> напоминание отправлено: {plate}")
            except Exception as exc:
                print(f"    -> ОШИБКА отправки напоминания для {plate}: {exc}")
        tracker.save()

    summary = (
        f"\nГотово. Новых записей: {new_count} (из них требуют проверки: {review_count}), "
        f"новых заявок в работу: {new_job_count}, "
        f"отредактированных/повторно проверенных сообщений: {edited_count}, "
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
