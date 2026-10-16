from __future__ import annotations

import os

os.environ.setdefault("MAX_BOT_TOKEN", "test-token-placeholder")
os.environ.setdefault("MAX_WEBHOOK_SECRET", "test-secret-ABC")
os.environ.setdefault("MAX_CHAT_ID", "-10")

from scripts import reconcile_new_orders as module


def _message(mid: str, text: str) -> dict:
    return {
        "sender": {"user_id": 7, "first_name": "Dispatcher", "is_bot": False},
        "recipient": {"chat_id": -10},
        "timestamp": 1_700_000_000_000,
        "body": {"mid": mid, "text": text},
    }


ORDER = """Новая заявка
Автомобиль
Н384РТ797
Откуда: Москва, улица А
Куда: Сервис (Москва, улица Б)
"""


def test_reconcile_uses_stable_mid_and_skips_unchanged(monkeypatch) -> None:
    synchronized: list[tuple[str, str]] = []
    monkeypatch.setattr(
        module,
        "sync_extracted_job",
        lambda job, chat_id, mid, text: synchronized.append((chat_id, mid)),
    )
    state: dict[str, str] = {}

    first = module.reconcile_messages([_message("mid-1", ORDER)], "-10", state)
    second = module.reconcile_messages([_message("mid-1", ORDER)], "-10", state)

    assert first["replayed"] == 1
    assert second["replayed"] == 0
    assert synchronized == [("-10", "mid-1")]


def test_only_plate_does_not_replay_other_orders(monkeypatch) -> None:
    synchronized: list[str] = []
    monkeypatch.setattr(
        module,
        "sync_extracted_job",
        lambda job, chat_id, mid, text: synchronized.append(mid),
    )
    other = ORDER.replace("Н384РТ797", "А742ОО797")

    result = module.reconcile_messages(
        [_message("wanted", ORDER), _message("other", other)],
        "-10",
        {},
        only_plates={"Н384РТ797"},
    )

    assert result["replayed"] == 1
    assert synchronized == ["wanted"]


def test_only_mid_replays_exact_source_message(monkeypatch) -> None:
    synchronized: list[str] = []
    monkeypatch.setattr(
        module,
        "sync_extracted_job",
        lambda job, chat_id, mid, text: synchronized.append(mid),
    )

    result = module.reconcile_messages(
        [_message("wanted", ORDER), _message("other", ORDER)],
        "-10",
        {},
        only_mids={"wanted"},
    )

    assert result["replayed"] == 1
    assert synchronized == ["wanted"]


def test_bootstrap_records_without_site_sync(monkeypatch) -> None:
    monkeypatch.setattr(
        module,
        "sync_extracted_job",
        lambda *args, **kwargs: (_ for _ in ()).throw(AssertionError("must not sync")),
    )
    state: dict[str, str] = {}

    result = module.reconcile_messages(
        [_message("mid-1", ORDER)], "-10", state, bootstrap=True
    )

    assert result["replayed"] == 0
    assert "mid-1" in state


def test_dry_run_classifies_without_sync_or_state_change(monkeypatch) -> None:
    monkeypatch.setattr(
        module,
        "sync_extracted_job",
        lambda *args, **kwargs: (_ for _ in ()).throw(AssertionError("must not sync")),
    )
    state: dict[str, str] = {}

    result = module.reconcile_messages(
        [_message("mid-1", ORDER)],
        "-10",
        state,
        only_plates={"Н384РТ797"},
        dry_run=True,
    )

    assert result["new_orders"] == 1
    assert result["replayed"] == 0
    assert state == {}

