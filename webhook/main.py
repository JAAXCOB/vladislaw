"""
MAX webhook receiver — production entry point.

Receives message_created events, runs AI extraction, writes the
result into the existing Excel file. Runs continuously on a server
(unlike scripts/poll.py, which is for local dev only).
"""
import json
import logging
import secrets
import sys
from typing import Any

from fastapi import BackgroundTasks, FastAPI, Header, HTTPException, Request, status
from pydantic import ValidationError

from webhook.config import settings
from webhook.excel_writer import append_job
from webhook.extractor import extract_job
from webhook.models import Update, UpdateType

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("max_webhook")

# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------

app = FastAPI(title="MAX Webhook", version="0.2.0")


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


def process_message(text: str, sender_name: str, timestamp_ms: int) -> None:
    """
    Runs AI extraction and writes the result to Excel.
    Executed as a background task so the webhook response isn't delayed.
    """
    try:
        job = extract_job(text, sender_name)
    except Exception:
        log.exception("Extraction failed for message: %r", text)
        return

    if not job.is_closed_job_report:
        log.info("Message does not report a closed job — skipping: %r", text)
        return

    if not settings.excel_file_path:
        log.warning("EXCEL_FILE_PATH not set — skipping Excel write")
        return

    try:
        sheet = append_job(settings.excel_file_path, job, timestamp_ms, text)
        log.info("Written to sheet '%s' (needs_review=%s)", sheet, job.needs_review)
    except Exception:
        log.exception("Failed to write to Excel for message: %r", text)


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

    # --- 5. Structured log for message_created ------------------------------------
    if update.update_type == UpdateType.message_created and update.message:
        msg = update.message
        sender_name = msg.sender.display_name if msg.sender else "unknown"
        sender_id = msg.sender.user_id if msg.sender else None
        chat_id = msg.recipient.chat_id if msg.recipient else None
        text = msg.resolve_text()  # falls back to link.message.text for forwarded messages
        mid = msg.body.mid if msg.body else None

        log.info(
            "MESSAGE_CREATED | mid=%s | chat_id=%s | from=%s (id=%s) | text=%r",
            mid,
            chat_id,
            sender_name,
            sender_id,
            text,
        )

        if text:
            background_tasks.add_task(process_message, text, sender_name, update.timestamp)
    else:
        log.info("UPDATE type=%s | timestamp=%s", update.update_type, update.timestamp)

    # --- 6. Always return 200 so MAX doesn't retry --------------------------------
    return {"ok": "true"}
