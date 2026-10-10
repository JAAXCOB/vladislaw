"""Read-only MAX diagnostics. Never sends messages or changes subscriptions.

Run: python -m scripts.diagnose_max --chat-id <B2B_CHAT_ID>
Credentials come from the environment/.env, never command-line arguments.
"""
from __future__ import annotations

import argparse
import json
import os
from typing import Any

import httpx
from dotenv import load_dotenv

MAX_API_BASE = "https://platform-api2.max.ru"


def _get(client: httpx.Client, path: str) -> tuple[dict[str, Any], dict[str, Any] | None]:
    try:
        response = client.get(path)
    except httpx.RequestError:
        # Exception messages and response bodies can contain sensitive data.
        return {"status": "network_or_tls_error"}, None
    summary: dict[str, Any] = {"http_status": response.status_code}
    if response.status_code != 200:
        summary["status"] = {
            401: "unauthorized", 403: "forbidden", 404: "not_found",
            429: "rate_limited",
        }.get(response.status_code, "http_error")
        return summary, None
    try:
        payload = response.json()
    except ValueError:
        summary["status"] = "invalid_response"
        return summary, None
    if not isinstance(payload, dict):
        summary["status"] = "invalid_response"
        return summary, None
    summary["status"] = "ok"
    return summary, payload


def diagnose(
    client: httpx.Client,
    chat_id: int,
    *,
    expected_bot_id: int | None = None,
    expected_webhook_url: str | None = None,
) -> dict[str, Any]:
    """Return an allowlisted summary; no names, messages, URLs or secrets."""
    report: dict[str, Any] = {"read_only": True, "message_delivery_tested": False}
    report["bot"], bot = _get(client, "/me")
    if bot is None:
        return report
    if bot.get("is_bot") is not True or not isinstance(bot.get("user_id"), int):
        report["bot"]["status"] = "invalid_response"
        return report
    if expected_bot_id is not None:
        report["bot"]["matches_expected"] = bot["user_id"] == expected_bot_id
        if not report["bot"]["matches_expected"]:
            report["bot"]["status"] = "wrong_bot"
            return report

    report["chat"], chat = _get(client, f"/chats/{chat_id}")
    if chat is not None:
        chat_status = chat.get("status")
        chat_type = chat.get("type")
        if chat.get("chat_id") != chat_id or chat_status not in {"active", "removed", "left", "closed"} or chat_type not in {"chat", "channel", "dialog"}:
            report["chat"]["status"] = "invalid_response"
        else:
            report["chat"]["active"] = chat_status == "active"
            report["chat"]["type"] = chat_type
            if chat_status != "active":
                report["chat"]["status"] = "inactive"

    report["membership"], member = _get(client, f"/chats/{chat_id}/members/me")
    if member is not None:
        permissions = member.get("permissions")
        if member.get("user_id") != bot["user_id"] or (permissions is not None and not isinstance(permissions, list)):
            report["membership"]["status"] = "invalid_response"
        else:
            report["membership"]["is_admin"] = member.get("is_admin") is True
            report["membership"]["permissions_reported"] = permissions is not None
            report["membership"]["write_permission_reported"] = any(
                p in (permissions or []) for p in ("write", "post_edit_delete_message")
            )
            # Ordinary chat members may have no permissions field and still send.
            # A read-only check cannot prove a POST /messages would succeed.

    report["subscriptions"], data = _get(client, "/subscriptions")
    if data is not None:
        subscriptions = data.get("subscriptions")
        if not isinstance(subscriptions, list) or not all(isinstance(s, dict) for s in subscriptions):
            report["subscriptions"]["status"] = "invalid_response"
        else:
            report["subscriptions"]["count"] = len(subscriptions)
            if expected_webhook_url:
                matches = [s for s in subscriptions if s.get("url") == expected_webhook_url]
                report["subscriptions"]["expected_url_found"] = bool(matches)
                for event in ("message_created", "message_edited", "message_removed", "message_callback"):
                    report["subscriptions"][event] = any(
                        s.get("update_types") is None
                        or isinstance(s.get("update_types"), list) and event in s["update_types"]
                        for s in matches
                    )
    return report


def main() -> int:
    load_dotenv()
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--chat-id", type=int)
    parser.add_argument("--expected-bot-id", type=int)
    args = parser.parse_args()
    token = os.getenv("MAX_BOT_TOKEN", "")
    raw_chat_id = os.getenv("MAX_B2B_CHAT_ID", "")
    try:
        chat_id = args.chat_id if args.chat_id is not None else int(raw_chat_id)
    except ValueError:
        print(json.dumps({"status": "missing_or_invalid_chat_id", "read_only": True}))
        return 2
    if not token.strip():
        print(json.dumps({"status": "missing_bot_token", "read_only": True}))
        return 2
    # Fixed official host and no redirects: credentials cannot be redirected.
    with httpx.Client(
        base_url=MAX_API_BASE, headers={"Authorization": token},
        timeout=15, follow_redirects=False,
    ) as client:
        report = diagnose(
            client, chat_id, expected_bot_id=args.expected_bot_id,
            expected_webhook_url=os.getenv("MAX_WEBHOOK_URL") or None,
        )
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 1 if any(
        isinstance(value, dict) and value.get("status") != "ok"
        for value in report.values()
    ) else 0


if __name__ == "__main__":
    raise SystemExit(main())
