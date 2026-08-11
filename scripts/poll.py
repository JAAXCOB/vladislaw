"""
Long-poll updater — alternative to webhook for local development.

Use this when you don't have a public HTTPS URL yet (before ngrok / VPS setup).
Requires NO webhook subscription — MAX delivers updates directly here.

Usage:
    python scripts/poll.py

Reads MAX_BOT_TOKEN from .env or environment.
Press Ctrl+C to stop.
"""
import json
import os
import sys
import time

import httpx
from dotenv import load_dotenv

load_dotenv()

import sys
sys.path.insert(0, str(__import__("pathlib").Path(__file__).parent.parent))
from webhook.extractor import extract_job
from webhook.excel_writer import append_job

MAX_API_BASE = os.getenv("MAX_API_BASE", "https://platform-api2.max.ru")
TOKEN = os.getenv("MAX_BOT_TOKEN", "")
POLL_TIMEOUT = 20  # seconds — MAX holds the connection up to this long


def main() -> None:
    if not TOKEN:
        sys.exit("ERROR: MAX_BOT_TOKEN is not set.")

    print("Starting long-poll loop. Press Ctrl+C to stop.\n")

    marker: int | None = None

    # verify=False: platform-api2.max.ru uses a Russian government CA (Минцифры)
    # not included in standard CA bundles. Safe for local dev polling only.
    with httpx.Client(timeout=POLL_TIMEOUT + 5, verify=False) as client:
        while True:
            params: dict[str, object] = {"timeout": POLL_TIMEOUT}
            if marker is not None:
                params["marker"] = marker

            try:
                resp = client.get(
                    f"{MAX_API_BASE}/updates",
                    headers={"Authorization": TOKEN},
                    params=params,
                )
            except httpx.TimeoutException:
                # Normal — no updates arrived during the timeout window
                continue
            except httpx.RequestError as exc:
                print(f"[network error] {exc} — retrying in 5s")
                time.sleep(5)
                continue

            if resp.status_code != 200:
                print(f"[error] HTTP {resp.status_code}: {resp.text[:200]}")
                time.sleep(5)
                continue

            data = resp.json()
            updates = data.get("updates", [])
            marker = data.get("marker", marker)

            for update in updates:
                print("=== UPDATE ===")
                print(json.dumps(update, ensure_ascii=False, indent=2))
                print()

                if update.get("update_type") == "message_created":
                    msg = update.get("message", {})
                    text = msg.get("body", {}).get("text")
                    sender = msg.get("sender", {})
                    sender_name = sender.get("first_name", "")

                    if text:
                        print("=== AI EXTRACTION ===")
                        try:
                            job = extract_job(text, sender_name)
                            print(json.dumps(job.model_dump(), ensure_ascii=False, indent=2))

                            excel_path = os.getenv("EXCEL_FILE_PATH", "")
                            if excel_path:
                                timestamp_ms = update.get("timestamp", 0)
                                sheet = append_job(excel_path, job, timestamp_ms, text)
                                review_mark = " ⚠️  ТРЕБУЕТ ПРОВЕРКИ" if job.needs_review else ""
                                print(f"=== EXCEL: записано в лист '{sheet}'{review_mark} ===")
                            else:
                                print("[excel] EXCEL_FILE_PATH не задан — запись пропущена")
                        except Exception as exc:
                            print(f"[extraction error] {exc}")
                        print()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nStopped.")
