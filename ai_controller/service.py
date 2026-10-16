from __future__ import annotations

import asyncio
import logging

import httpx

from .audit import AuditLog
from .budget import BudgetLedger, BudgetLimits
from .config import ControllerSettings
from .mail import GmailReadOnlyAdapter, YandexReadOnlyAdapter
from .max_control import MaxControlAdapter
from .models import ControllerSnapshot
from .monitors import ServiceMonitors
from .policy import deterministic_recommendations


log = logging.getLogger("avr_ai_controller")


class ControllerService:
    def __init__(self, settings: ControllerSettings) -> None:
        self.settings = settings
        self.settings.prepare_directories()
        self.client = httpx.AsyncClient(timeout=settings.request_timeout_seconds)
        self.audit = AuditLog(
            settings.data_dir / "audit.jsonl",
            settings.audit_hmac_key.get_secret_value(),
        )
        self.budget = BudgetLedger(
            settings.data_dir / "controller.sqlite3",
            BudgetLimits(
                paid_api_enabled=settings.paid_api_enabled,
                daily_minor=settings.daily_budget_minor,
                monthly_minor=settings.monthly_budget_minor,
                per_request_minor=settings.per_request_budget_minor,
            ),
        )
        self.monitors = ServiceMonitors(settings, self.client)
        self.yandex = YandexReadOnlyAdapter(settings)
        self.gmail = GmailReadOnlyAdapter(settings, self.client)
        self.snapshot = ControllerSnapshot()
        self._cycle_task: asyncio.Task[None] | None = None
        self._max_task: asyncio.Task[None] | None = None
        self._stopped = asyncio.Event()
        self.max_control = MaxControlAdapter(settings, self.client, self.audit, self.handle_command)

    async def run_cycle(self) -> None:
        try:
            probes = await self.monitors.run_all()
            yandex, gmail = await asyncio.gather(self.yandex.summary(), self.gmail.summary())
            snapshot = ControllerSnapshot(
                probes=probes,
                mailboxes={"yandex": yandex, "gmail": gmail},
                cycle=self.snapshot.cycle + 1,
            )
            snapshot.recommendations = deterministic_recommendations(snapshot)
            self.snapshot = snapshot
            self.audit.append(
                "observation_cycle",
                "controller",
                "completed",
                {
                    "cycle": snapshot.cycle,
                    "overall": snapshot.overall.value,
                    "unavailable": sum(
                        1 for item in snapshot.probes.values() if item.state.value == "unavailable"
                    ),
                },
            )
        except Exception as exc:
            log.exception("Controller observation cycle failed")
            self.snapshot.last_cycle_error = type(exc).__name__
            self.audit.append(
                "observation_cycle",
                "controller",
                "failed",
                {"error_type": type(exc).__name__},
            )

    async def _cycle_loop(self) -> None:
        while not self._stopped.is_set():
            await self.run_cycle()
            try:
                await asyncio.wait_for(
                    self._stopped.wait(),
                    timeout=self.settings.poll_interval_seconds,
                )
            except asyncio.TimeoutError:
                pass

    async def start(self) -> None:
        self.audit.append(
            "controller_start",
            "systemd",
            "started",
            {
                "paid_api_enabled": self.settings.paid_api_enabled,
                "max_control_enabled": self.settings.max_control_enabled,
                "max_control_transport": self.settings.max_control_transport,
                "gmail_enabled": self.settings.gmail_enabled,
                "yandex_enabled": self.settings.yandex_mail_enabled,
            },
        )
        self._cycle_task = asyncio.create_task(self._cycle_loop(), name="avr-ai-observer")
        if self.settings.max_control_transport == "polling":
            self._max_task = asyncio.create_task(self.max_control.run(), name="avr-ai-max-control")

    async def stop(self) -> None:
        self._stopped.set()
        self.max_control.stop()
        tasks = [task for task in (self._cycle_task, self._max_task) if task]
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)
        await self.client.aclose()
        self.audit.append("controller_stop", "systemd", "stopped", {})

    async def handle_command(self, command: str) -> str:
        if command == "help":
            return "AVR AI: /status, /budget, /mail, /help. Добавьте уникальный nonce после команды."
        if command == "budget":
            totals = self.budget.totals()
            return (
                "ИИ-бюджет: платные API "
                f"{'включены' if totals['paid_api_enabled'] else 'выключены'}; "
                f"сегодня {totals['day_minor']}/{totals['day_limit_minor']}; "
                f"месяц {totals['month_minor']}/{totals['month_limit_minor']}."
            )
        if command == "mail":
            parts = []
            for name, item in sorted(self.snapshot.mailboxes.items()):
                parts.append(f"{name}: {item.state.value}, непрочитанных {item.unread_count}")
            return "Почта read-only: " + ("; ".join(parts) if parts else "ещё не проверена")
        if command == "status":
            checks = ", ".join(
                f"{name}={item.state.value}" for name, item in sorted(self.snapshot.probes.items())
            )
            recommendation = self.snapshot.recommendations[0] if self.snapshot.recommendations else "нет данных"
            return f"AVR AI: {self.snapshot.overall.value}; {checks or 'цикл ещё не выполнен'}. {recommendation}"
        return "Команда не поддерживается."
