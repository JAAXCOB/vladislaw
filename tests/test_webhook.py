"""
Tests for the webhook endpoint using realistic mock MAX payloads.
No real MAX connection needed — everything runs against the local FastAPI app.

Run:
    pytest
"""
import json
import os
from copy import deepcopy

import pytest
from fastapi.testclient import TestClient

# Set env vars before importing the app so pydantic-settings picks them up
os.environ.setdefault("MAX_BOT_TOKEN", "test-token-placeholder")
os.environ.setdefault("MAX_WEBHOOK_SECRET", "test-secret-ABC")
os.environ.setdefault("MAX_WEBHOOK_URL", "https://example.com/webhook")
os.environ.setdefault("MAX_CHAT_ID", "987654321")

from webhook.extractor import _structured_request_overrides  # noqa: E402
from webhook import av_rescue_client, event_inbox  # noqa: E402
from webhook import open_jobs_tracker  # noqa: E402
import webhook.main as main_module  # noqa: E402
from webhook.main import app  # noqa: E402

VALID_SECRET = "test-secret-ABC"

# ---------------------------------------------------------------------------
# Realistic mock MAX payloads (based on official schema)
# ---------------------------------------------------------------------------

MESSAGE_CREATED_PAYLOAD = {
    "update_type": "message_created",
    "timestamp": 1723382400000,
    "message": {
        "sender": {
            "user_id": 111222333,
            "first_name": "Иван",
            "last_name": "Петров",
            "username": "ivan_petrov",
            "is_bot": False,
            "last_activity_time": 1723382390000,
        },
        "recipient": {
            "chat_id": 987654321,
            "chat_type": "chat",
            "user_id": None,
        },
        "timestamp": 1723382400000,
        "body": {
            "mid": "mid.abc123xyz",
            "seq": 42,
            "text": "Забрал BMW 530, госномер А123ВС777, с ул. Ленина 15, поставил на спецстоянку №3. Работа выполнена.",
            "attachments": None,
            "markup": None,
        },
        "link": None,
        "stat": None,
        "url": None,
    },
}

BOT_STARTED_PAYLOAD = {
    "update_type": "bot_started",
    "timestamp": 1723382000000,
    "chat_id": 987654321,
    "user": {
        "user_id": 111222333,
        "first_name": "Иван",
        "last_name": "Петров",
        "username": "ivan_petrov",
        "is_bot": False,
        "last_activity_time": 1723382000000,
    },
}

MESSAGE_INCOMPLETE_PAYLOAD = {
    "update_type": "message_created",
    "timestamp": 1723382500000,
    "message": {
        "sender": {
            "user_id": 444555666,
            "first_name": "Алексей",
            "is_bot": False,
        },
        "recipient": {
            "chat_id": 987654321,
            "chat_type": "chat",
        },
        "timestamp": 1723382500000,
        "body": {
            "mid": "mid.def456",
            "seq": 43,
            "text": "BMW забрал, отвез на стоянку.",
        },
    },
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

@pytest.fixture(autouse=True)
def isolated_persistence(tmp_path, monkeypatch) -> None:
    monkeypatch.setattr(event_inbox.settings, "event_inbox_path", str(tmp_path / "event-inbox"))
    monkeypatch.setattr(
        av_rescue_client.settings,
        "av_rescue_sync_queue_path",
        str(tmp_path / "partner-queue.json"),
    )
    monkeypatch.setattr(open_jobs_tracker, "STATE_PATH", tmp_path / "open-jobs.json")


@pytest.fixture
def client() -> TestClient:
    with TestClient(app) as test_client:
        yield test_client


def post_webhook(client: TestClient, payload: dict, secret: str = VALID_SECRET):
    return client.post(
        "/webhook",
        content=json.dumps(payload, ensure_ascii=False),
        headers={
            "Content-Type": "application/json",
            "X-Max-Bot-Api-Secret": secret,
        },
    )


def test_explicit_close_sync_uses_plate_and_stable_source_id(monkeypatch) -> None:
    captured = []
    monkeypatch.setattr(av_rescue_client, "_deliver_with_retry", captured.append)
    av_rescue_client.sync_explicit_close(
        -73220767988430,
        "mid.close-1",
        "Заявка закрыта А123ВС797",
    )
    assert captured == [{
        "event": "close",
        "source_id": "max:-73220767988430:mid.close-1",
        "source_chat_id": "-73220767988430",
        "source_message_id": "mid.close-1",
        "license_plate": "А123ВС797",
        "comment": "Заявка закрыта А123ВС797",
    }]


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

def test_health(client: TestClient) -> None:
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json()["status"] == "ok"
    assert "partner_sync_queue" in resp.json()
    assert "tracked_open_jobs" in resp.json()


def test_message_created_returns_200(client: TestClient) -> None:
    resp = post_webhook(client, MESSAGE_CREATED_PAYLOAD)
    assert resp.status_code == 200
    assert resp.json() == {"ok": "true"}


def test_authorized_message_is_persisted_before_success(client: TestClient, monkeypatch) -> None:
    captured = []

    def capture(payload):
        captured.append(payload)
        return "event-id", True

    monkeypatch.setattr(main_module, "enqueue", capture)
    resp = post_webhook(client, MESSAGE_CREATED_PAYLOAD)
    assert resp.status_code == 200
    assert captured == [MESSAGE_CREATED_PAYLOAD]


def test_message_from_non_allowlisted_chat_is_not_enqueued(client: TestClient, monkeypatch) -> None:
    payload = deepcopy(MESSAGE_CREATED_PAYLOAD)
    payload["message"]["recipient"]["chat_id"] = 123

    def must_not_run(_payload):
        raise AssertionError("unauthorized chat was enqueued")

    monkeypatch.setattr(main_module, "enqueue", must_not_run)
    assert post_webhook(client, payload).status_code == 200


def test_oversized_payload_is_rejected_before_parsing(client: TestClient) -> None:
    response = client.post(
        "/webhook",
        content=b"x" * 262_145,
        headers={"X-Max-Bot-Api-Secret": VALID_SECRET},
    )
    assert response.status_code == 413


def test_bot_started_returns_200(client: TestClient) -> None:
    resp = post_webhook(client, BOT_STARTED_PAYLOAD)
    assert resp.status_code == 200


def test_incomplete_message_returns_200(client: TestClient) -> None:
    """Even messages missing optional fields must return 200 — MAX must not retry."""
    resp = post_webhook(client, MESSAGE_INCOMPLETE_PAYLOAD)
    assert resp.status_code == 200


def test_wrong_secret_returns_403(client: TestClient) -> None:
    resp = post_webhook(client, MESSAGE_CREATED_PAYLOAD, secret="wrong-secret")
    assert resp.status_code == 403


def test_missing_secret_returns_403(client: TestClient) -> None:
    resp = client.post(
        "/webhook",
        content=json.dumps(MESSAGE_CREATED_PAYLOAD),
        headers={"Content-Type": "application/json"},
        # No X-Max-Bot-Api-Secret header
    )
    assert resp.status_code == 403


def test_invalid_json_returns_400(client: TestClient) -> None:
    resp = client.post(
        "/webhook",
        content=b"not json at all",
        headers={
            "Content-Type": "application/json",
            "X-Max-Bot-Api-Secret": VALID_SECRET,
        },
    )
    assert resp.status_code == 400


def test_unknown_update_type_does_not_crash(client: TestClient) -> None:
    """Future MAX event types we haven't modelled yet must not break the server."""
    payload = {"update_type": "some_future_event_type", "timestamp": 1723382999000}
    resp = post_webhook(client, payload)
    assert resp.status_code == 200


def test_structured_partner_request_fields() -> None:
    text = """Примите, пожалуйста, заявку на эвакуатор на сегодня

Город: Москва
Тестовый автомобиль
а000аа000
Откуда: 55.750000, 37.620000
Куда: ТЕСТОВЫЙ СЕРВИС (МОСКВА, ТЕСТОВАЯ УЛ., 1)
Тариф: эконом
Телефон: 89990000000
Комментарий: Диагностика системы

Крюка нет.
"""
    fields = _structured_request_overrides(text)
    assert fields["pickup_lat"] == 55.75
    assert fields["pickup_lng"] == 37.62
    assert fields["pickup_address"] == "55.750000, 37.620000"
    assert fields["destination"] == "ТЕСТОВЫЙ СЕРВИС (МОСКВА, ТЕСТОВАЯ УЛ., 1)"
    assert fields["customer_phone"] == "89990000000"
    assert fields["customer_comment"] == "Диагностика системы\n\nКрюка нет."
