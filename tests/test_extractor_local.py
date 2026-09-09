from webhook.extractor import _local_extraction, extract_job


STANDARD_REQUEST = """Примите, пожалуйста, заявку на эвакуатор на сегодня

Город: Москва

Coolray (BelGee)

е523тт797

Откуда: 55.567055, 37.485786

Куда: CIDR | FIT ЮНЫХ ЛЕНИНЦЕВ (ПОДОЛЬСК, ЮНЫХ ЛЕНИНЦЕВ ПР-КТ, 11)

Тариф: эконом

Телефон: 89263021998

Комментарий: Слесарный. диагностика системы ож

Крюка нет."""


def test_standard_request_is_parsed_without_yandex(monkeypatch):
    def fail_if_called(*args, **kwargs):
        raise AssertionError("YandexGPT must not be called for a standard request")

    monkeypatch.setattr("httpx.Client", fail_if_called)
    job = extract_job(STANDARD_REQUEST)

    assert job.is_new_job_request
    assert not job.is_closed_job_report
    assert job.license_plate == "Е523ТТ797"
    assert (job.vehicle_make, job.vehicle_model) == ("BelGee", "Coolray")
    assert (job.pickup_lat, job.pickup_lng) == (55.567055, 37.485786)
    assert job.customer_phone == "89263021998"
    assert job.customer_comment.endswith("Крюка нет.")


def test_reminder_and_acceptance_are_not_new_requests():
    reminder, reminder_source = _local_extraction(
        "⚠️ Заявка Е523ТТ797 всё ещё не закрыта. Не забудьте отчитаться о выполнении"
    )
    accepted, accepted_source = _local_extraction(
        "Заявка принята Е523ТТ797, эвакуация 4500"
    )

    assert reminder_source == "local_reminder"
    assert accepted_source == "local_status"
    assert not reminder.is_new_job_request
    assert not accepted.is_new_job_request


def test_regular_closed_report_is_parsed_without_yandex():
    job, source = _local_extraction(
        "Заявка закрыта Е523ТТ797 эвакуация 4500 + 2 км от МКАД 180"
    )

    assert source == "local_closed"
    assert job.is_closed_job_report
    assert job.total_amount_rub == 4680


def test_chat_is_ignored_and_ambiguous_close_uses_ai_fallback():
    chat, chat_source = _local_extraction("Добрый день, буду через десять минут")
    ambiguous, ambiguous_source = _local_extraction("Закрыл заказ Е523ТТ797, сумма 5000")

    assert chat_source == "local_irrelevant"
    assert not chat.is_new_job_request
    assert ambiguous is None
    assert ambiguous_source == "yandex"
