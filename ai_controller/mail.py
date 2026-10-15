from __future__ import annotations

import asyncio
import imaplib
from email import message_from_bytes
from email.header import decode_header
from typing import Any

import httpx

from .config import ControllerSettings
from .models import HealthState, MailboxSummary


def _decode_header(value: str | None) -> str:
    if not value:
        return ""
    parts = []
    for chunk, encoding in decode_header(value):
        if isinstance(chunk, bytes):
            parts.append(chunk.decode(encoding or "utf-8", errors="replace"))
        else:
            parts.append(chunk)
    return "".join(parts)[:200]


class YandexReadOnlyAdapter:
    def __init__(self, settings: ControllerSettings) -> None:
        self.settings = settings

    def _read(self) -> MailboxSummary:
        connection = imaplib.IMAP4_SSL(
            self.settings.yandex_imap_host,
            self.settings.yandex_imap_port,
            timeout=self.settings.request_timeout_seconds,
        )
        try:
            connection.login(
                self.settings.yandex_mailbox,
                self.settings.yandex_app_password.get_secret_value(),
            )
            status, _ = connection.select("INBOX", readonly=True)
            if status != "OK":
                raise RuntimeError("read-only INBOX selection failed")
            status, rows = connection.uid("search", None, "UNSEEN")
            if status != "OK":
                raise RuntimeError("UNSEEN search failed")
            uids = rows[0].split() if rows and rows[0] else []
            sample = uids[-self.settings.mail_sample_limit :]
            for uid in sample:
                status, data = connection.uid(
                    "fetch",
                    uid,
                    "(BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID)] RFC822.SIZE)",
                )
                if status != "OK":
                    raise RuntimeError("header fetch failed")
                for item in data:
                    if isinstance(item, tuple) and isinstance(item[1], bytes):
                        message = message_from_bytes(item[1])
                        _decode_header(message.get("Subject"))
            return MailboxSummary(
                provider="yandex",
                state=HealthState.healthy,
                unread_count=len(uids),
                sampled_count=len(sample),
                detail="read-only IMAP BODY.PEEK",
            )
        finally:
            try:
                connection.logout()
            except Exception:
                pass

    async def summary(self) -> MailboxSummary:
        if not self.settings.yandex_mail_enabled:
            return MailboxSummary(provider="yandex", state=HealthState.disabled, detail="disabled")
        try:
            return await asyncio.to_thread(self._read)
        except Exception as exc:
            return MailboxSummary(
                provider="yandex",
                state=HealthState.unavailable,
                detail=type(exc).__name__,
            )


class GmailReadOnlyAdapter:
    def __init__(self, settings: ControllerSettings, client: httpx.AsyncClient) -> None:
        self.settings = settings
        self.client = client

    async def _access_token(self) -> str:
        response = await self.client.post(
            self.settings.gmail_token_url,
            data={
                "client_id": self.settings.gmail_client_id,
                "client_secret": self.settings.gmail_client_secret.get_secret_value(),
                "refresh_token": self.settings.gmail_refresh_token.get_secret_value(),
                "grant_type": "refresh_token",
            },
        )
        response.raise_for_status()
        token = str(response.json().get("access_token", ""))
        if not token:
            raise RuntimeError("OAuth response has no access token")
        return token

    async def summary(self) -> MailboxSummary:
        if not self.settings.gmail_enabled:
            return MailboxSummary(provider="gmail", state=HealthState.disabled, detail="disabled")
        try:
            token = await self._access_token()
            headers = {"Authorization": f"Bearer {token}"}
            response = await self.client.get(
                f"{self.settings.gmail_api_base.rstrip('/')}/users/me/messages",
                headers=headers,
                params={
                    "q": "is:unread",
                    "maxResults": self.settings.mail_sample_limit,
                    "fields": "resultSizeEstimate,messages/id",
                },
            )
            response.raise_for_status()
            payload: dict[str, Any] = response.json()
            messages = payload.get("messages") or []
            for item in messages[: self.settings.mail_sample_limit]:
                message_id = str(item.get("id", ""))
                if not message_id:
                    continue
                metadata = await self.client.get(
                    f"{self.settings.gmail_api_base.rstrip('/')}/users/me/messages/{message_id}",
                    headers=headers,
                    params={
                        "format": "metadata",
                        "metadataHeaders": ["From", "Subject", "Date", "Message-ID"],
                        "fields": "id,internalDate,payload/headers",
                    },
                )
                metadata.raise_for_status()
            return MailboxSummary(
                provider="gmail",
                state=HealthState.healthy,
                unread_count=int(payload.get("resultSizeEstimate", len(messages))),
                sampled_count=len(messages),
                detail="gmail.readonly metadata",
            )
        except (httpx.HTTPError, ValueError, RuntimeError) as exc:
            return MailboxSummary(
                provider="gmail",
                state=HealthState.unavailable,
                detail=type(exc).__name__,
            )
