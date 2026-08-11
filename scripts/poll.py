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

MAX_API_BASE = os.getenv("MAX_API_BASE", "https://platform-api2.max.ru")
TOKEN = os.getenv("MAX_BOT_TOKEN", "")
POLL_TIMEOUT = 20  # seconds — MAX holds the connection up to this long


def main() -> None:
    if not TOKEN:
        sys.exit("ERROR: MAX_BOT_TOKEN is not set.")

    print("Starting long-poll loop. Press Ctrl+C to stop.\n")

    marker: int | None = None

    with httpx.Client(timeout=POLL_TIMEOUT + 5) as client:
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


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nStopped.")
