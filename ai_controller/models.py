from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum

from pydantic import BaseModel, Field


class HealthState(str, Enum):
    healthy = "healthy"
    degraded = "degraded"
    unavailable = "unavailable"
    disabled = "disabled"


class ProbeResult(BaseModel):
    name: str
    state: HealthState
    checked_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    latency_ms: int | None = None
    detail: str = ""


class MailboxSummary(BaseModel):
    provider: str
    state: HealthState
    unread_count: int = 0
    sampled_count: int = 0
    detail: str = ""


class ControllerSnapshot(BaseModel):
    generated_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    probes: dict[str, ProbeResult] = Field(default_factory=dict)
    mailboxes: dict[str, MailboxSummary] = Field(default_factory=dict)
    recommendations: list[str] = Field(default_factory=list)
    cycle: int = 0
    last_cycle_error: str = ""

    @property
    def overall(self) -> HealthState:
        states = [item.state for item in self.probes.values()]
        if not states:
            return HealthState.degraded
        if HealthState.unavailable in states:
            return HealthState.unavailable
        if HealthState.degraded in states:
            return HealthState.degraded
        return HealthState.healthy
