"""
MAX webhook receiver — production entry point.

Receives new and edited messages, runs one AI extraction, synchronizes the
operational AV Rescue feed and keeps the existing Excel reporting. Runs continuously on a server
(unlike scripts/poll.py, which is for local dev only).
"""
import logging
import secrets
import sys
from typing import Any

from fastapi import FastAPI, Header, HTTPException, Request, status
from pydantic import ValidationError

from webhook.config import settings
from webhook.av_rescue_client import pending_sync_count, sync_extracted_job
from webhook.event_inbox import enqueue, pending_count as inbox_pending_count, start_worker, stop_worker
from webhook.excel_writer import append_job
from webhook.extractor import extract_job
from webhook.models import Update, UpdateType
from webhook.open_jobs_tracker import OpenJobsTracker
from webhook.payroll_writer import append_salary_row, ensure_employee_column
from webhook.reporting_rules import employee_header

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

app = FastAPI(title="MAX Webhook", version="0.3.0")


@app.on_event("startup")
def ensure_payroll_structure() -> None:
    """Apply safe, idempotent payroll schema updates before accepting events."""
    if not settings.payroll_file_path:
        log.warning("PAYROLL_FILE_PATH not set — payroll structure was not checked")
    else:
        for employee_name in ("Буревич Антон", "Николай Большаков", "Бодров Максим"):
            sheet, column, created = ensure_employee_column(
                settings.payroll_file_path,
                employee_name,
            )
            log.info(
                "Payroll employee column ready | sheet='%s' | column=%d | created=%s",
                sheet,
                column,
                created,
            )
    start_worker(process_update_payload)


@app.on_event("shutdown")
def shutdown_worker() -> None:
    stop_worker()


@app.get("/health")
async def health() -> dict[str, str | int]:
    tracked_open_jobs = 0
    for chat_id in settings.allowed_chat_ids:
        tracked_open_jobs += len(OpenJobsTracker(chat_id).list_open_jobs())
    return {
        "status": "ok",
        "partner_sync_queue": pending_sync_count(),
        "event_inbox": inbox_pending_count(),
        "tracked_open_jobs": tracked_open_jobs,
    }


def process_message(
    text: str,
    sender_name: str,
    employee_name: str,
    timestamp_ms: int,
    chat_id: int | None,
    message_id: str | None,
    is_edited: bool = False,
) -> None:
    """
    Runs AI extraction and writes the result to Excel (and payroll, if configured).
    Executed by the durable inbox worker after the webhook is acknowledged.
    """
    job = extract_job(text, sender_name)

    sync_extracted_job(job, chat_id, message_id, text)

    if not job.is_closed_job_report:
        log.info("Message does not report a closed job | mid=%s", message_id)
        return

    if not settings.excel_file_path:
        log.warning("EXCEL_FILE_PATH not set — skipping Excel write")
        return

    sheet, inserted = append_job(
        settings.excel_file_path,
        job,
        timestamp_ms,
        text,
        message_id or "",
        is_edited,
    )
    if inserted:
        log.info("Written to sheet '%s' (needs_review=%s)", sheet, job.needs_review)
    else:
        log.info("Existing MAX message in sheet '%s' was updated or skipped", sheet)

    if settings.payroll_file_path:
        payroll_sheet, matched, inserted = append_salary_row(
            settings.payroll_file_path,
            job,
            timestamp_ms,
            employee_header(employee_name),
            text,
            message_id or "",
            is_edited,
        )
        log.info(
            "Payroll sheet '%s' (matched=%s, inserted=%s)",
            payroll_sheet,
            matched,
            inserted,
        )


def process_update_payload(raw_json: dict[str, Any]) -> None:
    """Process one already-authenticated event from the durable inbox."""
    update = Update.model_validate(raw_json)
    if update.update_type not in (UpdateType.message_created, UpdateType.message_edited) or not update.message:
        return
    msg = update.message
    chat_id = msg.recipient.chat_id if msg.recipient else None
    if str(chat_id) not in settings.allowed_chat_ids:
        return
    text = msg.resolve_text()
    if not text:
        return
    process_message(
        text,
        msg.sender.display_name if msg.sender else "unknown",
        msg.effective_sender_name(),
        update.timestamp,
        chat_id,
        msg.body.mid if msg.body else None,
        update.update_type == UpdateType.message_edited,
    )


@app.post("/webhook", status_code=status.HTTP_200_OK)
async def webhook(
    request: Request,
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
    content_length = request.headers.get("content-length")
    if content_length and content_length.isdigit() and int(content_length) > 262_144:
        raise HTTPException(status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, detail="Payload too large")
    raw_body = await request.body()
    if len(raw_body) > 262_144:
        raise HTTPException(status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, detail="Payload too large")
    try:
        raw_json = await request.json()
        if not isinstance(raw_json, dict):
            raise ValueError("JSON root must be an object")
    except (ValueError, UnicodeDecodeError):
        log.warning("Rejected invalid JSON body | bytes=%d", len(raw_body))
        raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="Invalid JSON")

    # --- 4. Parse into typed model (best-effort) --------------------------------
    try:
        update = Update.model_validate(raw_json)
    except ValidationError as exc:
        # Don't fail — we still want 200 so MAX doesn't retry.
        # Validation errors here just mean our model is incomplete.
        log.warning("Rejected unsupported/invalid update | errors=%d", exc.error_count())
        return {"ok": "true"}

    # --- 5. Process new and edited job messages ------------------------------------
    if update.update_type in (UpdateType.message_created, UpdateType.message_edited) and update.message:
        msg = update.message
        sender_id = msg.sender.user_id if msg.sender else None
        chat_id = msg.recipient.chat_id if msg.recipient else None
        mid = msg.body.mid if msg.body else None

        log.info(
            "%s | mid=%s | chat_id=%s | sender_id=%s",
            update.update_type.value.upper(),
            mid,
            chat_id,
            sender_id,
        )

        if str(chat_id) not in settings.allowed_chat_ids:
            log.warning("Ignored event from unauthorized chat | chat_id=%s | mid=%s", chat_id, mid)
        else:
            identifier, created = enqueue(raw_json)
            log.info(
                "Webhook durably accepted | event_id=%s | duplicate=%s | mid=%s",
                identifier[:12],
                not created,
                mid,
            )
    else:
        log.info("UPDATE type=%s | timestamp=%s", update.update_type, update.timestamp)

    # --- 6. Always return 200 so MAX doesn't retry --------------------------------
    return {"ok": "true"}
