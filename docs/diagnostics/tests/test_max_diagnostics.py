import json

import httpx
import pytest

from scripts.diagnose_max import diagnose


def mock_client(overrides=None):
    responses = {
        "/me": (200, {"user_id": 1, "is_bot": True, "first_name": "PRIVATE_NAME"}),
        "/chats/-2": (200, {"chat_id": -2, "type": "chat", "status": "active", "title": "PRIVATE_CHAT"}),
        "/chats/-2/members/me": (200, {"user_id": 1, "is_admin": False}),
        "/subscriptions": (200, {"subscriptions": [{
            "url": "https://example.com/webhook?key=PRIVATE_URL_KEY",
            "update_types": ["message_created"], "secret": "PRIVATE_SECRET",
        }]}),
    }
    responses.update(overrides or {})
    calls = []

    def handler(request):
        assert request.method == "GET"
        assert request.headers["Authorization"] == "PRIVATE_TOKEN"
        calls.append(request.url.path)
        status, data = responses[request.url.path]
        return httpx.Response(status, json=data)

    return httpx.Client(
        transport=httpx.MockTransport(handler), base_url="https://platform-api2.max.ru",
        headers={"Authorization": "PRIVATE_TOKEN"},
    ), calls


def test_diagnostics_only_reads_and_excludes_sensitive_data():
    client, calls = mock_client()
    with client:
        report = diagnose(client, -2, expected_bot_id=1, expected_webhook_url="https://example.com/webhook?key=PRIVATE_URL_KEY")
    assert calls == ["/me", "/chats/-2", "/chats/-2/members/me", "/subscriptions"]
    assert "PRIVATE" not in json.dumps(report)
    assert report["message_delivery_tested"] is False
    assert report["membership"]["permissions_reported"] is False
    assert report["membership"]["status"] == "ok"
    assert report["subscriptions"]["message_created"] is True
    assert report["subscriptions"]["message_edited"] is False


def test_invalid_token_stops_requests():
    client, calls = mock_client({"/me": (401, {"message": "PRIVATE_RESPONSE"})})
    with client:
        report = diagnose(client, -2)
    assert calls == ["/me"]
    assert report["bot"]["status"] == "unauthorized"
    assert "PRIVATE" not in json.dumps(report)


def test_wrong_bot_stops_before_chat_queries():
    client, calls = mock_client()
    with client:
        report = diagnose(client, -2, expected_bot_id=999)
    assert calls == ["/me"]
    assert report["bot"]["status"] == "wrong_bot"


@pytest.mark.parametrize("status, expected", [(403, "forbidden"), (404, "not_found"), (429, "rate_limited"), (500, "http_error"), (302, "http_error")])
def test_chat_access_errors_are_explicit(status, expected):
    client, _ = mock_client({"/chats/-2": (status, {"message": "PRIVATE_RESPONSE"})})
    with client:
        report = diagnose(client, -2)
    assert report["chat"]["status"] == expected
    assert "PRIVATE" not in json.dumps(report)


def test_removed_bot_is_not_reported_as_active():
    client, _ = mock_client({"/chats/-2": (200, {"chat_id": -2, "type": "chat", "status": "removed"})})
    with client:
        report = diagnose(client, -2)
    assert report["chat"]["status"] == "inactive"
    assert report["chat"]["active"] is False


def test_empty_subscriptions_do_not_imply_outgoing_message_failure():
    client, _ = mock_client({"/subscriptions": (200, {"subscriptions": []})})
    with client:
        report = diagnose(client, -2)
    assert report["subscriptions"]["count"] == 0
    assert report["subscriptions"]["status"] == "ok"
    assert report["message_delivery_tested"] is False


def test_network_exception_is_redacted():
    def handler(request):
        raise httpx.ConnectError("PRIVATE_TLS_DETAIL", request=request)
    with httpx.Client(transport=httpx.MockTransport(handler), base_url="https://platform-api2.max.ru") as client:
        report = diagnose(client, -2)
    assert report["bot"]["status"] == "network_or_tls_error"
    assert "PRIVATE" not in json.dumps(report)
