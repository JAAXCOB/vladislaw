"""
Outbound MAX API calls — sending messages back into a chat.
"""
from __future__ import annotations

import logging

import httpx

log = logging.getLogger("max_webhook.max_client")


def send_message(chat_id: str, text: str, bot_token: str, api_base: str) -> None:
    """
    Sends a text message into a chat via POST /messages.
    Raises RuntimeError on a non-200 response.
    """
    with httpx.Client(verify=False, timeout=15) as client:
        resp = client.post(
            f"{api_base}/messages",
            headers={
                "Authorization": bot_token,
                "Content-Type": "application/json",
            },
            params={"chat_id": chat_id},
            json={"text": text},
        )

    if resp.status_code != 200:
        raise RuntimeError(f"send_message failed: {resp.status_code} {resp.text[:300]}")

    log.info("Sent message to chat_id=%s: %r", chat_id, text)
