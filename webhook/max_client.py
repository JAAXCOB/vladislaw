"""
Outbound MAX API calls — sending messages back into a chat.
"""
from __future__ import annotations

import logging
from typing import Optional

import httpx

log = logging.getLogger("max_webhook.max_client")


def send_message(
    chat_id: str,
    text: str,
    bot_token: str,
    api_base: str,
    reply_to_mid: Optional[str] = None,
) -> None:
    """
    Sends a text message into a chat via POST /messages.

    When reply_to_mid is given, the message is sent as a reply to that
    message (NewMessageLink type=reply) — MAX shows the quoted original
    inline, and tapping it jumps straight to it, so the recipient doesn't
    have to scroll up looking for the original job request.

    Raises RuntimeError on a non-200 response.
    """
    body: dict = {"text": text}
    if reply_to_mid:
        body["link"] = {"type": "reply", "mid": reply_to_mid}

    with httpx.Client(verify=False, timeout=15) as client:
        resp = client.post(
            f"{api_base}/messages",
            headers={
                "Authorization": bot_token,
                "Content-Type": "application/json",
            },
            params={"chat_id": chat_id},
            json=body,
        )

    if resp.status_code != 200:
        raise RuntimeError(f"send_message failed: {resp.status_code} {resp.text[:300]}")

    log.info("Sent message to chat_id=%s (reply_to=%s): %r", chat_id, reply_to_mid, text)
