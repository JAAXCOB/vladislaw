from __future__ import annotations

from .models import ControllerSnapshot, HealthState


def deterministic_recommendations(snapshot: ControllerSnapshot) -> list[str]:
    """Fail-safe pilot router: observations become advice, never commands."""
    advice: list[str] = []
    for name, probe in sorted(snapshot.probes.items()):
        if probe.state == HealthState.unavailable:
            advice.append(f"Проверить {name}: сервис недоступен ({probe.detail}).")
        elif probe.state == HealthState.degraded:
            advice.append(f"Проверить {name}: наблюдается деградация ({probe.detail}).")
    for name, mailbox in sorted(snapshot.mailboxes.items()):
        if mailbox.state == HealthState.unavailable:
            advice.append(f"Проверить read-only подключение почты {name}.")
        elif mailbox.state == HealthState.healthy and mailbox.unread_count:
            advice.append(f"В {name} есть непрочитанные письма: {mailbox.unread_count}.")
    if not advice:
        advice.append("Критичных отклонений не обнаружено; автоматические действия не требуются.")
    return advice
