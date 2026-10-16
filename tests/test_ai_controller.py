from __future__ import annotations

import json
import time
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

import httpx
import pytest
from pydantic import ValidationError

from ai_controller.audit import AuditLog
from ai_controller.app import create_app
from ai_controller.budget import BudgetDenied, BudgetLedger, BudgetLimits
from ai_controller.config import ControllerSettings
from ai_controller.mail import GmailReadOnlyAdapter, YandexReadOnlyAdapter
from ai_controller.max_control import MaxControlAdapter
from ai_controller.models import ControllerSnapshot, HealthState, ProbeResult
from ai_controller.monitors import ServiceMonitors
from ai_controller.policy import deterministic_recommendations


@dataclass
class RecordedRequest:
    method: str
    url: str
    kwargs: dict


class FakeResponse:
    def __init__(self, status_code: int, payload: dict) -> None:
        self.status_code = status_code
        self._payload = payload

    def json(self) -> dict:
        return self._payload

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            raise RuntimeError(f"HTTP {self.status_code}")


class FakeAsyncClient:
    def __init__(self, handler) -> None:
        self.handler = handler

    async def get(self, url: str, **kwargs) -> FakeResponse:
        return self.handler(RecordedRequest("GET", str(url), kwargs))

    async def post(self, url: str, **kwargs) -> FakeResponse:
        return self.handler(RecordedRequest("POST", str(url), kwargs))


def run_immediate(coroutine):
    """Run coroutines whose test doubles complete without an event-loop wait.

    The bundled Windows validation runtime cannot create an asyncio event loop;
    production uses the normal uvicorn event loop on Linux.
    """
    try:
        coroutine.send(None)
    except StopIteration as completed:
        return completed.value
    raise AssertionError("test coroutine unexpectedly required an event loop")


def settings(tmp_path: Path, **overrides) -> ControllerSettings:
    values = {
        "data_dir": tmp_path / "controller",
        "site_health_url": "https://site.example/health",
        "max_health_url": "http://127.0.0.1:8000/health",
        "osrm_base_url": "https://osrm.example",
        "audit_hmac_key": "a" * 32,
    }
    values.update(overrides)
    return ControllerSettings(_env_file=None, **values)


def test_settings_fail_closed_for_transport_paid_api_and_max_allowlist(tmp_path: Path) -> None:
    with pytest.raises(ValidationError, match="HTTPS or a loopback"):
        settings(tmp_path, site_health_url="http://site.example/health")
    with pytest.raises(ValidationError, match="budgets must be positive"):
        settings(tmp_path, paid_api_enabled=True)
    with pytest.raises(ValidationError, match="replace the example"):
        settings(tmp_path, audit_hmac_key="replace-with-at-least-32-random-characters")
    with pytest.raises(ValidationError, match="user id"):
        settings(
            tmp_path,
            max_control_enabled=True,
            max_control_transport="webhook",
            max_control_token="token",
            max_control_webhook_secret="s" * 32,
            max_control_chat_id="-100",
            max_control_user_ids="name",
        )
    with pytest.raises(ValidationError, match="webhook secret"):
        settings(
            tmp_path,
            max_control_enabled=True,
            max_control_transport="webhook",
            max_control_token="token",
            max_control_chat_id="-100",
            max_control_user_ids="42",
        )


def test_budget_is_disabled_by_default_and_enforced_atomically(tmp_path: Path) -> None:
    disabled = BudgetLedger(tmp_path / "disabled.sqlite3", BudgetLimits(False, 0, 0, 0))
    with pytest.raises(BudgetDenied, match="disabled"):
        disabled.reserve("request-1", "ops", "provider", 1)

    ledger = BudgetLedger(tmp_path / "enabled.sqlite3", BudgetLimits(True, 100, 200, 60))
    reservation = ledger.reserve("request-1", "ops", "provider", 60)
    assert ledger.reserve("request-1", "ops", "provider", 60) == reservation
    ledger.settle(reservation, 40)
    assert ledger.totals()["day_minor"] == 40
    with pytest.raises(BudgetDenied, match="per-request"):
        ledger.reserve("request-2", "ops", "provider", 61)
    ledger.reserve("request-3", "ops", "provider", 60)
    with pytest.raises(BudgetDenied, match="daily"):
        ledger.reserve("request-4", "ops", "provider", 1)


def test_audit_is_hmac_chained_and_omits_sensitive_fields(tmp_path: Path) -> None:
    path = tmp_path / "audit.jsonl"
    audit = AuditLog(path, "k" * 32)
    audit.append("cycle", "controller", "ok", {"count": 1, "token": "secret"})
    audit.append("command", "operator", "ok", {"command": "status", "text": "private"})
    valid, count = audit.verify()
    assert (valid, count) == (True, 2)
    assert "secret" not in path.read_text(encoding="utf-8")
    assert "private" not in path.read_text(encoding="utf-8")

    rows = path.read_text(encoding="utf-8").splitlines()
    changed = json.loads(rows[0])
    changed["outcome"] = "tampered"
    rows[0] = json.dumps(changed)
    path.write_text("\n".join(rows) + "\n", encoding="utf-8")
    assert audit.verify()[0] is False


def test_site_max_and_osrm_monitors_are_get_only(tmp_path: Path) -> None:
    requests: list[RecordedRequest] = []

    def handler(request: RecordedRequest) -> FakeResponse:
        requests.append(request)
        if "nearest" in str(request.url):
            return FakeResponse(200, {"code": "Ok", "waypoints": [{}]})
        return FakeResponse(200, {"status": "ok"})

    monitors = ServiceMonitors(settings(tmp_path), FakeAsyncClient(handler))
    result = {
        "site": run_immediate(monitors.site()),
        "max": run_immediate(monitors.max_webhook()),
        "osrm": run_immediate(monitors.osrm()),
    }

    assert set(result) == {"site", "max", "osrm"}
    assert all(item.state == HealthState.healthy for item in result.values())
    assert all(request.method == "GET" for request in requests)


@pytest.mark.asyncio
async def test_monitors_run_concurrently_on_a_real_event_loop(tmp_path: Path) -> None:
    requests: list[RecordedRequest] = []

    def handler(request: RecordedRequest) -> FakeResponse:
        requests.append(request)
        if "nearest" in request.url:
            return FakeResponse(200, {"code": "Ok", "waypoints": [{}]})
        return FakeResponse(200, {"status": "ok"})

    result = await ServiceMonitors(settings(tmp_path), FakeAsyncClient(handler)).run_all()

    assert set(result) == {"site", "max", "osrm"}
    assert all(item.state == HealthState.healthy for item in result.values())
    assert [request.method for request in requests] == ["GET", "GET", "GET"]


def test_gmail_adapter_uses_only_token_exchange_and_read_endpoints(tmp_path: Path) -> None:
    requests: list[RecordedRequest] = []

    def handler(request: RecordedRequest) -> FakeResponse:
        requests.append(request)
        path = urlparse(request.url).path
        if path.endswith("/token"):
            return FakeResponse(200, {"access_token": "short-lived"})
        if path.endswith("/messages"):
            return FakeResponse(200, {"resultSizeEstimate": 1, "messages": [{"id": "m1"}]})
        return FakeResponse(200, {"id": "m1", "payload": {"headers": []}})

    configured = settings(
        tmp_path,
        gmail_enabled=True,
        gmail_client_id="client",
        gmail_client_secret="secret",
        gmail_refresh_token="refresh",
        gmail_token_url="https://oauth.example/token",
        gmail_api_base="https://gmail.example/gmail/v1",
    )
    summary = run_immediate(GmailReadOnlyAdapter(configured, FakeAsyncClient(handler)).summary())

    assert summary.state == HealthState.healthy
    assert [request.method for request in requests] == ["POST", "GET", "GET"]
    assert not any("modify" in str(request.url) for request in requests)


def test_yandex_adapter_uses_readonly_select_and_body_peek(tmp_path: Path, monkeypatch) -> None:
    calls: list[tuple] = []

    class FakeImap:
        def __init__(self, *args, **kwargs):
            calls.append(("connect", args, kwargs))

        def login(self, mailbox, password):
            calls.append(("login", mailbox, password))

        def select(self, mailbox, readonly=False):
            calls.append(("select", mailbox, readonly))
            return "OK", [b"1"]

        def uid(self, command, *args):
            calls.append(("uid", command, *args))
            if command == "search":
                return "OK", [b"10"]
            return "OK", [(b"header", b"Subject: Test\r\nMessage-ID: <1>\r\n\r\n")]

        def logout(self):
            calls.append(("logout",))

    monkeypatch.setattr("ai_controller.mail.imaplib.IMAP4_SSL", FakeImap)
    configured = settings(
        tmp_path,
        yandex_mail_enabled=True,
        yandex_mailbox="robot@example.test",
        yandex_app_password="app-password",
    )
    result = YandexReadOnlyAdapter(configured)._read()

    assert result.state == HealthState.healthy
    assert ("select", "INBOX", True) in calls
    fetch = next(call for call in calls if call[:2] == ("uid", "fetch"))
    assert "BODY.PEEK" in fetch[3]
    assert not any(call[:2] == ("uid", "store") for call in calls)


def test_closed_max_control_requires_chat_user_freshness_and_nonce(tmp_path: Path) -> None:
    sent: list[RecordedRequest] = []

    def handler(request: RecordedRequest) -> FakeResponse:
        sent.append(request)
        return FakeResponse(200, {"message": {"body": {"mid": "reply"}}})

    async def command_handler(command: str) -> str:
        return f"handled:{command}"

    configured = settings(
        tmp_path,
        max_control_enabled=True,
        max_control_transport="webhook",
        max_control_token="controller-token",
        max_control_webhook_secret="w" * 32,
        max_control_chat_id="-100",
        max_control_user_ids="42",
    )
    audit = AuditLog(tmp_path / "max-audit.jsonl", "m" * 32)
    adapter = MaxControlAdapter(configured, FakeAsyncClient(handler), audit, command_handler)
    assert adapter.accepts_webhook_secret("w" * 32) is True
    assert adapter.accepts_webhook_secret("wrong") is False
    update = {
        "update_type": "message_created",
        "timestamp": int(time.time() * 1000),
        "message": {
            "sender": {"user_id": 42},
            "recipient": {"chat_id": -100},
            "body": {"text": "/status nonce_123"},
        },
    }
    assert run_immediate(adapter.process_update(update)) is True
    assert run_immediate(adapter.process_update(update)) is False
    unauthorized = json.loads(json.dumps(update))
    unauthorized["message"]["sender"]["user_id"] = 43
    unauthorized["message"]["body"]["text"] = "/status nonce_456"
    assert run_immediate(adapter.process_update(unauthorized)) is False
    expired = json.loads(json.dumps(update))
    expired["timestamp"] = 1
    expired["message"]["body"]["text"] = "/status nonce_789"
    assert run_immediate(adapter.process_update(expired)) is False

    assert len(sent) == 1
    assert sent[0].method == "POST"
    assert urlparse(sent[0].url).path.endswith("/messages")


def test_closed_max_webhook_is_a_dedicated_post_route(tmp_path: Path) -> None:
    application = create_app(settings(tmp_path))
    route = next(item for item in application.routes if item.path == "/max-control/webhook")
    assert route.methods == {"POST"}


@pytest.mark.asyncio
async def test_closed_max_webhook_rejects_missing_and_wrong_secret(tmp_path: Path) -> None:
    configured = settings(
        tmp_path,
        max_control_enabled=True,
        max_control_transport="webhook",
        max_control_token="controller-token",
        max_control_webhook_secret="w" * 32,
        max_control_chat_id="-100",
        max_control_user_ids="42",
    )
    transport = httpx.ASGITransport(app=create_app(configured))
    async with httpx.AsyncClient(transport=transport, base_url="http://controller.test") as client:
        missing = await client.post("/max-control/webhook", json={})
        wrong = await client.post(
            "/max-control/webhook",
            headers={"X-Max-Bot-Api-Secret": "wrong"},
            json={},
        )

    assert missing.status_code == 403
    assert wrong.status_code == 403


@pytest.mark.asyncio
async def test_closed_max_webhook_accepts_valid_secret_but_ignores_untrusted_input(
    tmp_path: Path,
) -> None:
    configured = settings(
        tmp_path,
        max_control_enabled=True,
        max_control_transport="webhook",
        max_control_token="controller-token",
        max_control_webhook_secret="w" * 32,
        max_control_chat_id="-100",
        max_control_user_ids="42",
    )
    application = create_app(configured)
    transport = httpx.ASGITransport(app=application)
    headers = {"X-Max-Bot-Api-Secret": "w" * 32}
    timestamp = int(time.time() * 1000)
    wrong_user = {
        "update_type": "message_created",
        "timestamp": timestamp,
        "message": {
            "sender": {"user_id": 43},
            "recipient": {"chat_id": -100},
            "body": {"text": "/status nonce_123"},
        },
    }
    unsupported_command = {
        "update_type": "message_created",
        "timestamp": timestamp,
        "message": {
            "sender": {"user_id": 42},
            "recipient": {"chat_id": -100},
            "body": {"text": "/restart nonce_456"},
        },
    }

    async with httpx.AsyncClient(transport=transport, base_url="http://controller.test") as client:
        wrong_user_response = await client.post(
            "/max-control/webhook", headers=headers, json=wrong_user
        )
        unsupported_response = await client.post(
            "/max-control/webhook", headers=headers, json=unsupported_command
        )

    assert wrong_user_response.status_code == 200
    assert wrong_user_response.json() == {"ok": True, "processed": False}
    assert unsupported_response.status_code == 200
    assert unsupported_response.json() == {"ok": True, "processed": False}


@pytest.mark.asyncio
async def test_max_poll_requests_only_message_created_updates(tmp_path: Path) -> None:
    requests: list[RecordedRequest] = []

    def handler(request: RecordedRequest) -> FakeResponse:
        requests.append(request)
        return FakeResponse(200, {"updates": [], "marker": 123})

    configured = settings(
        tmp_path,
        max_control_enabled=True,
        max_control_transport="polling",
        max_control_token="controller-token",
        max_control_chat_id="-100",
        max_control_user_ids="42",
    )
    audit = AuditLog(tmp_path / "poll-audit.jsonl", "p" * 32)

    async def command_handler(command: str) -> str:
        return command

    adapter = MaxControlAdapter(configured, FakeAsyncClient(handler), audit, command_handler)
    await adapter.poll_once()

    assert requests[0].kwargs["params"]["types"] == "message_created"
    assert adapter.state.marker() == 123


def test_policy_only_recommends_and_controller_has_no_excel_or_payroll_writer() -> None:
    snapshot = ControllerSnapshot(
        probes={
            "site": ProbeResult(name="site", state=HealthState.unavailable, detail="timeout"),
        }
    )
    advice = deterministic_recommendations(snapshot)
    assert advice == ["Проверить site: сервис недоступен (timeout)."]

    root = Path(__file__).resolve().parents[1] / "ai_controller"
    code = "\n".join(path.read_text(encoding="utf-8") for path in root.glob("*.py"))
    assert "excel_writer" not in code
    assert "payroll_writer" not in code
    assert "clear_open_job" not in code


def test_systemd_unit_is_unprivileged_and_isolated_from_max_and_excel() -> None:
    root = Path(__file__).resolve().parents[1]
    unit = (root / "ops/systemd/avr-ai-controller.service").read_text(encoding="utf-8")
    assert "User=avrai" in unit
    assert "Group=avrai" in unit
    assert "NoNewPrivileges=true" in unit
    assert "CapabilityBoundingSet=" in unit
    assert "AmbientCapabilities=" in unit
    assert "WorkingDirectory=/opt/vladislaw/ai-controller/current" in unit
    assert "MemoryMax=384M" in unit
    assert "CPUQuota=50%" in unit
    assert "RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX" in unit
    assert "User=root" not in unit
    assert "data/excel" not in unit
    assert "max-webhook.service" not in unit


def test_example_configuration_disables_paid_and_write_capable_integrations() -> None:
    root = Path(__file__).resolve().parents[1]
    example = (root / ".env.ai-controller.example").read_text(encoding="utf-8")
    assert "AVR_AI_PAID_API_ENABLED=false" in example
    assert "AVR_AI_DAILY_BUDGET_MINOR=0" in example
    assert "AVR_AI_MONTHLY_BUDGET_MINOR=0" in example
    assert "AVR_AI_PER_REQUEST_BUDGET_MINOR=0" in example
    assert "AVR_AI_MAX_CONTROL_ENABLED=false" in example
    assert "AVR_AI_MAX_CONTROL_TRANSPORT=webhook" in example
    assert "AVR_AI_DATA_DIR=/opt/vladislaw/ai-controller-data" in example
    assert "SMTP" not in example

