"""
Register (or update) the webhook subscription with MAX.

Usage:
    python scripts/register_webhook.py

Reads MAX_BOT_TOKEN, MAX_WEBHOOK_SECRET, MAX_WEBHOOK_URL from .env or environment.
Run once after the bot token is approved and your HTTPS endpoint is live.
"""
import json
import os
import sys

import httpx
from dotenv import load_dotenv

load_dotenv()

MAX_API_BASE = os.getenv("MAX_API_BASE", "https://platform-api2.max.ru")
TOKEN = os.getenv("MAX_BOT_TOKEN", "")
SECRET = os.getenv("MAX_WEBHOOK_SECRET", "")
WEBHOOK_URL = os.getenv("MAX_WEBHOOK_URL", "")

SUBSCRIBE_ONLY_TYPES = ["message_created"]


def main() -> None:
    if not TOKEN:
        sys.exit("ERROR: MAX_BOT_TOKEN is not set.")
    if not SECRET:
        sys.exit("ERROR: MAX_WEBHOOK_SECRET is not set.")
    if not WEBHOOK_URL:
        sys.exit("ERROR: MAX_WEBHOOK_URL is not set.")
    if not WEBHOOK_URL.startswith("https://"):
        sys.exit("ERROR: MAX_WEBHOOK_URL must start with https://")

    payload = {
        "url": WEBHOOK_URL,
        "update_types": SUBSCRIBE_ONLY_TYPES,
        "secret": SECRET,
    }

    print(f"Subscribing to MAX webhook...")
    print(f"  URL:          {WEBHOOK_URL}")
    print(f"  Update types: {SUBSCRIBE_ONLY_TYPES}")
    print()

    with httpx.Client() as client:
        resp = client.post(
            f"{MAX_API_BASE}/subscriptions",
            headers={
                "Authorization": TOKEN,
                "Content-Type": "application/json",
            },
            json=payload,
            timeout=15,
        )

    print(f"HTTP {resp.status_code}")
    try:
        print(json.dumps(resp.json(), ensure_ascii=False, indent=2))
    except Exception:
        print(resp.text)

    if resp.status_code == 200:
        print("\nWebhook registered successfully.")
    else:
        print("\nRegistration failed — check token and URL.")
        sys.exit(1)


if __name__ == "__main__":
    main()
