from __future__ import annotations

import asyncio
import hmac
import re
import sqlite3
import time
from collections.abc import Awaitable, Callable
from pathlib import Path
from typing import Any

import httpx

from .audit import AuditLog
from .config import ControllerSettings


COMMAND_PATTERN = re.compile(r"^/(status|budget|mail|help)\s+([A-Za-z0-9_-]{8,64})$")
CommandHandler = Callable[[str], Awaitable[str]]


class MaxControlState:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        with self._connect() as connection:
            connection.executescript(
                """
                CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS command_nonce (
                    nonce TEXT PRIMARY KEY,
                    received_at INTEGER NOT NULL
                );
                """
            )

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.path, timeout=10)
        connection.execute("PRAGMA journal_mode=WAL")
        connection.execute("PRAGMA busy_timeout=10000")
        return connection

    def marker(self) -> int | None:
        with self._connect() as connection:
            row = connection.execute("SELECT value FROM state WHERE key='marker'").fetchone()
        return int(row[0]) if row else None

    def save_marker(self, marker: int) -> None:
        with self._connect() as connection:
            connection.execute(
                "INSERT INTO state(key, value) VALUES('marker', ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (str(marker),),
            )

    def consume_nonce(self, nonce: str, now_seconds: int) -> bool:
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            connection.execute(
                "DELETE FROM command_nonce WHERE received_at < ?",
                (now_seconds - 86400,),
            )
            try:
                connection.execute(
                    "INSERT INTO command_nonce(nonce, received_at) VALUES(?, ?)",
                    (nonce, now_seconds),
                )
            except sqlite3.IntegrityError:
                connection.rollback()
                return False
            connection.commit()
            return True

    def release_nonce(self, nonce: str) -> None:
        with self._connect() as connection:
            connection.execute("DELETE FROM command_nonce WHERE nonce = ?", (nonce,))
            connection.commit()


class MaxControlAdapter:
    def __init__(
        self,
        settings: ControllerSettings,
        client: httpx.AsyncClient,
        audit: AuditLog,
        handler: CommandHandler,
    ) -> None:
        self.settings = settings
        self.client = client
        self.audit = audit
        self.handler = handler
        self.state = MaxControlState(settings.data_dir / "max-control.sqlite3")
        self._stopped = asyncio.Event()

    @property
    def headers(self) -> dict[str, str]:
        return {"Authorization": self.settings.max_control_token.get_secret_value()}

    def accepts_webhook_secret(self, supplied: str) -> bool:
        expected = self.settings.max_control_webhook_secret.get_secret_value()
        return bool(expected) and hmac.compare_digest(supplied, expected)

    @staticmethod
    def _chat_id(update: dict[str, Any]) -> str:
        message = update.get("message") or {}
        recipient = message.get("recipient") or {}
        return str(update.get("chat_id") or recipient.get("chat_id") or "")

    @staticmethod
    def _user_id(update: dict[str, Any]) -> str:
        message = update.get("message") or {}
        sender = message.get("sender") or update.get("user") or {}
        return str(sender.get("user_id") or "")

    async def _send(self, chat_id: str, text: str) -> None:
        response = await self.client.post(
            f"{self.settings.max_control_api_base.rstrip('/')}/messages",
            headers={**self.headers, "Content-Type": "application/json"},
            params={"chat_id": chat_id},
            json={"text": text[:3000]},
        )
        if response.status_code != 200:
            raise RuntimeError(f"MAX control send failed with HTTP {response.status_code}")

    async def process_update(self, update: dict[str, Any]) -> bool:
        if update.get("update_type") != "message_created":
            return False
        chat_id = self._chat_id(update)
        user_id = self._user_id(update)
        if chat_id != self.settings.max_control_chat_id or user_id not in self.settings.allowed_control_user_ids:
            self.audit.append(
                "max_control_command",
                "unauthorized",
                "denied",
                {"chat_match": chat_id == self.settings.max_control_chat_id, "user_match": False},
            )
            return False

        message = update.get("message") or {}
        body = message.get("body") or {}
        text = str(body.get("text") or "")[:256].strip()
        match = COMMAND_PATTERN.fullmatch(text)
        if not match:
            return False
        command, nonce = match.groups()
        timestamp_ms = int(update.get("timestamp") or message.get("timestamp") or 0)
        now_seconds = int(time.time())
        if not timestamp_ms or abs(now_seconds - timestamp_ms // 1000) > self.settings.max_control_command_ttl_seconds:
            self.audit.append("max_control_command", f"max-user:{user_id}", "expired", {"command": command})
            return False
        if not self.state.consume_nonce(nonce, now_seconds):
            self.audit.append("max_control_command", f"max-user:{user_id}", "replay", {"command": command})
            return False

        try:
            response_text = await self.handler(command)
            await self._send(chat_id, response_text)
        except Exception as exc:
            # Read-only control commands are safe to retry. Do not permanently
            # burn the nonce when the reply could not be delivered.
            self.state.release_nonce(nonce)
            self.audit.append(
                "max_control_command",
                f"max-user:{user_id}",
                "delivery_failed",
                {"command": command, "error_type": type(exc).__name__},
            )
            raise
        self.audit.append("max_control_command", f"max-user:{user_id}", "completed", {"command": command})
        return True

    async def poll_once(self) -> None:
        marker = self.state.marker()
        params: dict[str, int | str] = {
            "timeout": self.settings.max_control_poll_timeout_seconds,
            "types": "message_created",
        }
        if marker is not None:
            params["marker"] = marker
        response = await self.client.get(
            f"{self.settings.max_control_api_base.rstrip('/')}/updates",
            headers=self.headers,
            params=params,
            timeout=self.settings.max_control_poll_timeout_seconds + 5,
        )
        if response.status_code != 200:
            raise RuntimeError(f"MAX control poll failed with HTTP {response.status_code}")
        payload = response.json()
        for update in payload.get("updates") or []:
            await self.process_update(update)
        next_marker = payload.get("marker")
        if isinstance(next_marker, int):
            self.state.save_marker(next_marker)

    async def run(self) -> None:
        if not self.settings.max_control_enabled or self.settings.max_control_transport != "polling":
            return
        delay = 1
        while not self._stopped.is_set():
            try:
                await self.poll_once()
                delay = 1
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                self.audit.append(
                    "max_control_poll",
                    "controller",
                    "failed",
                    {"error_type": type(exc).__name__},
                )
                try:
                    await asyncio.wait_for(self._stopped.wait(), timeout=delay)
                except asyncio.TimeoutError:
                    pass
                delay = min(delay * 2, 60)

    def stop(self) -> None:
        self._stopped.set()
