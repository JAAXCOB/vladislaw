"""
MAX webhook receiver — production entry point.

Receives new and edited messages, runs one AI extraction, synchronizes the
operational AV Rescue feed and keeps the existing Excel reporting. Runs continuously on a server
(unlike scripts/poll.py, which is for local dev only).
"""
import json
import logging
import secrets
import sys
import threading
from pathlib import Path
from typing import Any

from fastapi import BackgroundTasks, FastAPI, Header, HTTPException, Request, status
from pydantic import ValidationError

from webhook.config import settings
from webhook.av_rescue_client import sync_extracted_job
from webhook.excel_writer import append_job
from webhook.extractor import extract_job
from webhook.models import Update, UpdateType
from webhook.open_jobs_tracker import OpenJobsTracker
from webhook.payroll_writer import append_salary_row
from webhook.reporting_rules import employee_header, parse_explicit_closed_report, report_is_writable

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

# Guard against non-UTF-8 stdout (e.g. Windows cp1251 when redirected to a
# file) crashing the process on emoji/unusual characters in chat messages.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("max_webhook")

# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------

app = FastAPI(title="MAX Webhook", version="0.4.0")
_open_jobs_lock = threading.Lock()
_excel_write_lock = threading.Lock()


@app.get("/health")
async def health() -> dict[str, Any]:
    queue_path = Path(settings.av_rescue_sync_queue_path)
    if not queue_path.is_absolute():
        queue_path = Path(__file__).resolve().parent.parent / queue_path
    try:
        queue_data = json.loads(queue_path.read_text(encoding="utf-8")) if queue_path.exists() else []
        queue_count = len(queue_data) if isinstance(queue_data, list) else 0
    except Exception:
        queue_count = -1

    try:
        tracked_open_jobs = len(OpenJobsTracker(settings.max_chat_id).list_open_jobs()) if settings.max_chat_id else 0
    except Exception:
        tracked_open_jobs = -1

    return {
        "status": "ok",
        "av_rescue": "configured"
        if settings.av_rescue_api_url and settings.av_rescue_api_key
        else "not_configured",
        "partner_sync_queue": queue_count,
        "tracked_open_jobs": tracked_open_jobs,
    }


def process_message(
    text: str,
    sender_name: str,
    employee_name: str,
    timestamp_ms: int,
    chat_id: int | None,
    message_id: str | None,
) -> None:
    """
    Runs AI extraction and writes the result to Excel (and payroll, if configured).
    Executed as a background task so the webhook response isn't delayed.
    """
    try:
        job = extract_job(text, sender_name)
    except Exception:
        log.exception("Extraction failed for message: %r", text)
        return

    deterministic_report = parse_explicit_closed_report(text)
    if deterministic_report is not None:
        job = deterministic_report

    if chat_id is not None and job.license_plate and (job.is_new_job_request or job.is_closed_job_report):
        try:
            with _open_jobs_lock:
                tracker = OpenJobsTracker(str(chat_id))
                if job.is_closed_job_report:
                    tracker.mark_closed(job.license_plate)
                else:
                    tracker.register_new_job(job.license_plate, message_id or "", text)
                tracker.save()
        except Exception:
            log.exception("Open-job tracking failed")

    try:
        sync_extracted_job(job, chat_id, message_id, text)
    except Exception:
        # Reporting must continue even when the site is temporarily unavailable.
        log.exception("AV Rescue synchronization failed for message: %r", text)

    if not job.is_closed_job_report:
        log.info("Message does not report a closed job — skipping Excel: %r", text)
        return

    if not report_is_writable(job):
        log.warning(
            "Closed message rejected for Excel: a plate, services and positive amount are required | mid=%s",
            message_id,
        )
        return

    with _excel_write_lock:
        if settings.excel_file_path:
            try:
                sheet, inserted = append_job(settings.excel_file_path, job, timestamp_ms, text)
                if inserted:
                    log.info("Written to sheet '%s' (needs_review=%s)", sheet, job.needs_review)
                else:
                    log.info("Duplicate already exists in sheet '%s' — skipped", sheet)
            except Exception:
                log.exception("Failed to write to Excel for message: %r", text)
        else:
            log.warning("EXCEL_FILE_PATH not set — skipping evacuation report")

        # Payroll is independent: an error in the ordinary report must never
        # prevent the employee amount from being recorded.
        if settings.payroll_file_path:
            try:
                payroll_sheet, matched, inserted = append_salary_row(
                    settings.payroll_file_path,
                    job,
                    timestamp_ms,
                    employee_header(employee_name),
                    text,
                )
                log.info(
                    "Payroll sheet '%s' (employee=%s, matched=%s, inserted=%s)",
                    payroll_sheet,
                    employee_name,
                    matched,
                    inserted,
                )
            except Exception:
                log.exception("Failed to write to payroll file for message: %r", text)
        else:
            log.warning("PAYROLL_FILE_PATH not set — skipping payroll report")


@app.post("/webhook", status_code=status.HTTP_200_OK)
async def webhook(
    request: Request,
    background_tasks: BackgroundTasks,
    x_max_bot_api_secret: str = Header(default=""),
) -> dict[str, str]:
    """
    Receives MAX Bot API events.

    MAX sends X-Max-Bot-Api-Secret on every request when a secret was
    provided during subscription (POST /subscriptions). We compare it
    with constant-time comparison to avoid timing attacks.
    """
    # --- 1. Verify webhook secret ------------------------------------------
    if not secrets.compare_digest(x_max_bot_api_secret, settings.max_webhook_secret):
        log.warning("Rejected request: invalid X-Max-Bot-Api-Secret")
        raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Forbidden")

    # --- 2. Read raw body -----------------------------------------------------
    raw_body = await request.body()
    try:
        raw_json: dict[str, Any] = json.loads(raw_body)
    except json.JSONDecodeError:
        log.error("Received non-JSON body: %s", raw_body[:200])
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid JSON")

    # --- 3. Log full raw payload (Phase 1 goal) --------------------------------
    log.info("=== RAW MAX UPDATE ===\n%s", json.dumps(raw_json, ensure_ascii=False, indent=2))

    # --- 4. Parse into typed model (best-effort) --------------------------------
    try:
        update = Update.model_validate(raw_json)
    except ValidationError as exc:
        # Don't fail — we still want 200 so MAX doesn't retry.
        # Validation errors here just mean our model is incomplete.
        log.warning("Update parsed with validation issues: %s", exc)
        return {"ok": "true"}

    # --- 5. Process new and edited job messages ------------------------------------
    if update.update_type in (UpdateType.message_created, UpdateType.message_edited) and update.message:
        msg = update.message
        sender_name = msg.sender.display_name if msg.sender else "unknown"
        sender_id = msg.sender.user_id if msg.sender else None
        chat_id = msg.recipient.chat_id if msg.recipient else None
        text = msg.resolve_text()  # falls back to link.message.text for forwarded messages
        mid = msg.body.mid if msg.body else None

        log.info(
            "%s | mid=%s | chat_id=%s | from=%s (id=%s) | text=%r",
            update.update_type.value.upper(),
            mid,
            chat_id,
            sender_name,
            sender_id,
            text,
        )

        configured_chat = str(settings.max_chat_id).strip()
        if configured_chat and str(chat_id) != configured_chat:
            log.info("Ignoring message from chat %s (configured chat: %s)", chat_id, configured_chat)
        elif text:
            employee_name = msg.effective_sender_name()
            background_tasks.add_task(
                process_message,
                text,
                sender_name,
                employee_name,
                update.timestamp,
                chat_id,
                mid,
            )
    else:
        log.info("UPDATE type=%s | timestamp=%s", update.update_type, update.timestamp)

    # --- 6. Always return 200 so MAX doesn't retry --------------------------------
    return {"ok": "true"}
