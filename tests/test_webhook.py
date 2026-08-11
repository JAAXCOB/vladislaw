"""
Tests for the webhook endpoint using realistic mock MAX payloads.
No real MAX connection needed — everything runs against the local FastAPI app.

Run:
    pytest
"""
import json
import os

import pytest
from fastapi.testclient import TestClient

# Set env vars before importing the app so pydantic-settings picks them up
os.environ.setdefault("MAX_BOT_TOKEN", "test-token-placeholder")
os.environ.setdefault("MAX_WEBHOOK_SECRET", "test-secret-ABC")
os.environ.setdefault("MAX_WEBHOOK_URL", "https://example.com/webhook")

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

@pytest.fixture
def client() -> TestClient:
    return TestClient(app)


def post_webhook(client: TestClient, payload: dict, secret: str = VALID_SECRET):
    return client.post(
        "/webhook",
        content=json.dumps(payload, ensure_ascii=False),
        headers={
            "Content-Type": "application/json",
            "X-Max-Bot-Api-Secret": secret,
        },
    )


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

def test_health(client: TestClient) -> None:
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.json() == {"status": "ok"}


def test_message_created_returns_200(client: TestClient) -> None:
    resp = post_webhook(client, MESSAGE_CREATED_PAYLOAD)
    assert resp.status_code == 200
    assert resp.json() == {"ok": "true"}


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
