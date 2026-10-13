from __future__ import annotations

import sqlite3
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path


class BudgetDenied(RuntimeError):
    pass


@dataclass(frozen=True)
class BudgetLimits:
    paid_api_enabled: bool
    daily_minor: int
    monthly_minor: int
    per_request_minor: int


class BudgetLedger:
    def __init__(self, path: Path, limits: BudgetLimits) -> None:
        self.path = path
        self.limits = limits
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        self._initialize()

    def _connect(self) -> sqlite3.Connection:
        connection = sqlite3.connect(self.path, timeout=10, isolation_level=None)
        connection.execute("PRAGMA journal_mode=WAL")
        connection.execute("PRAGMA busy_timeout=10000")
        return connection

    def _initialize(self) -> None:
        with self._connect() as connection:
            connection.execute(
                """
                CREATE TABLE IF NOT EXISTS ai_spend (
                    reservation_id TEXT PRIMARY KEY,
                    request_key TEXT NOT NULL UNIQUE,
                    agent TEXT NOT NULL,
                    provider TEXT NOT NULL,
                    day TEXT NOT NULL,
                    month TEXT NOT NULL,
                    reserved_minor INTEGER NOT NULL CHECK (reserved_minor >= 0),
                    actual_minor INTEGER,
                    status TEXT NOT NULL CHECK (status IN ('reserved', 'settled', 'released')),
                    created_at TEXT NOT NULL
                )
                """
            )

    def reserve(self, request_key: str, agent: str, provider: str, maximum_minor: int) -> str:
        if not self.limits.paid_api_enabled:
            raise BudgetDenied("paid API calls are disabled")
        if maximum_minor <= 0 or maximum_minor > self.limits.per_request_minor:
            raise BudgetDenied("request exceeds the per-request budget")

        now = datetime.now(timezone.utc)
        day = now.date().isoformat()
        month = day[:7]
        reservation_id = str(uuid.uuid4())
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            existing = connection.execute(
                "SELECT reservation_id FROM ai_spend WHERE request_key = ?",
                (request_key,),
            ).fetchone()
            if existing:
                connection.execute("ROLLBACK")
                return str(existing[0])
            day_total = connection.execute(
                "SELECT COALESCE(SUM(CASE WHEN status='settled' THEN actual_minor ELSE reserved_minor END), 0) "
                "FROM ai_spend WHERE day = ? AND status != 'released'",
                (day,),
            ).fetchone()[0]
            month_total = connection.execute(
                "SELECT COALESCE(SUM(CASE WHEN status='settled' THEN actual_minor ELSE reserved_minor END), 0) "
                "FROM ai_spend WHERE month = ? AND status != 'released'",
                (month,),
            ).fetchone()[0]
            if day_total + maximum_minor > self.limits.daily_minor:
                connection.execute("ROLLBACK")
                raise BudgetDenied("daily budget exhausted")
            if month_total + maximum_minor > self.limits.monthly_minor:
                connection.execute("ROLLBACK")
                raise BudgetDenied("monthly budget exhausted")
            connection.execute(
                "INSERT INTO ai_spend VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'reserved', ?)",
                (
                    reservation_id,
                    request_key,
                    agent,
                    provider,
                    day,
                    month,
                    maximum_minor,
                    now.isoformat(),
                ),
            )
            connection.execute("COMMIT")
        return reservation_id

    def settle(self, reservation_id: str, actual_minor: int) -> None:
        with self._connect() as connection:
            connection.execute("BEGIN IMMEDIATE")
            row = connection.execute(
                "SELECT reserved_minor, status FROM ai_spend WHERE reservation_id = ?",
                (reservation_id,),
            ).fetchone()
            if not row or row[1] != "reserved":
                connection.execute("ROLLBACK")
                raise BudgetDenied("reservation is missing or already finalized")
            if actual_minor < 0 or actual_minor > row[0]:
                connection.execute("ROLLBACK")
                raise BudgetDenied("actual cost exceeds the reservation")
            connection.execute(
                "UPDATE ai_spend SET actual_minor = ?, status = 'settled' WHERE reservation_id = ?",
                (actual_minor, reservation_id),
            )
            connection.execute("COMMIT")

    def release(self, reservation_id: str) -> None:
        with self._connect() as connection:
            connection.execute(
                "UPDATE ai_spend SET status = 'released', actual_minor = 0 "
                "WHERE reservation_id = ? AND status = 'reserved'",
                (reservation_id,),
            )

    def totals(self) -> dict[str, int | bool]:
        today = datetime.now(timezone.utc).date().isoformat()
        month = today[:7]
        with self._connect() as connection:
            day = connection.execute(
                "SELECT COALESCE(SUM(CASE WHEN status='settled' THEN actual_minor ELSE reserved_minor END), 0) "
                "FROM ai_spend WHERE day = ? AND status != 'released'",
                (today,),
            ).fetchone()[0]
            monthly = connection.execute(
                "SELECT COALESCE(SUM(CASE WHEN status='settled' THEN actual_minor ELSE reserved_minor END), 0) "
                "FROM ai_spend WHERE month = ? AND status != 'released'",
                (month,),
            ).fetchone()[0]
        return {
            "paid_api_enabled": self.limits.paid_api_enabled,
            "day_minor": int(day),
            "day_limit_minor": self.limits.daily_minor,
            "month_minor": int(monthly),
            "month_limit_minor": self.limits.monthly_minor,
        }
