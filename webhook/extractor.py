"""
Phase 2 — AI extraction of structured job data from employee messages.
Uses YandexGPT via REST API with JSON output parsing.
"""
from __future__ import annotations

import json
import logging
import re

import httpx

from webhook.schema import Confidence, ExtractedJob, JobStatus

log = logging.getLogger("max_webhook.extractor")

SYSTEM_PROMPT = """Ты — система извлечения данных для бизнеса по эвакуации (буксировке) автомобилей в России.
Тебе приходят сообщения из рабочего группового чата в мессенджере. Нас интересуют сообщения ДВУХ типов:
1. Заявка ЗАКРЫТА / РАБОТА ВЫПОЛНЕНА — сотрудник отчитывается о завершённой работе
2. НОВАЯ ЗАЯВКА — диспетчер/клиент присылает новую заявку, которую нужно принять в работу
   (обычно начинается с "Примите заявку", "Новая заявка", содержит адрес/машину/номер/телефон)
Всё остальное (приветствия, вопросы, флуд, посторонний разговор) — не наша задача.

Твоя задача — извлечь структурированные данные и вернуть ТОЛЬКО валидный JSON без пояснений.

ПЕРВЫМ ДЕЛОМ определи тип сообщения — is_closed_job_report и is_new_job_request
(сообщение не может быть одновременно и тем и другим, максимум один из двух true):

is_closed_job_report = true — ТОЛЬКО если сообщение явно говорит, что ЗАЯВКА (работа/заказ)
  закрыта/выполнена. Формулировки бывают очень разговорными и корявыми, распознавай смысл, а не
  точную фразу. Примеры: "заявка закрыта", "закрыл", "сдал", "выполнено", "готово", "отработали",
  "все ок сдано", "забрал и отвёз", "поставил на стоянку", "довёз", "закрыта заявка",
  "затарил на стоянку", "все, привезли", "выполнил заказ" и любые похожие по смыслу формулировки
  о завершении РАБОТЫ.
  ВАЖНО: слово "закрыта"/"закрыл" может относиться не к заявке, а к чему-то другому — например
  "машина закрыта" (заперты двери автомобиля, это НЕ отчёт о завершении работы!), "дверь закрыта",
  "шлагбаум закрыт" и т.п. Не путай физическое состояние предмета с завершением заявки.

is_new_job_request = true — сообщение это НОВАЯ заявка, которую нужно принять в работу.
  Обычно содержит структурированные поля: город, марку/модель машины, госномер, адрес откуда,
  адрес куда, тариф, телефон клиента, комментарий. Может начинаться с "Примите", "Новая заявка"
  и т.п., но не обязательно — определяй по смыслу (описание задачи на эвакуацию, которую ещё
  предстоит выполнить). Извлеки хотя бы license_plate, если он указан — остальные поля закрытия
  (services, total_amount_rub, статус выполнения) для этого типа не нужны, оставь null/пусто.
  Дополнительно извлеки координаты подачи и назначения, если они прямо указаны в тексте или ссылке,
  а также время работы/крайний срок сервиса в service_until. Координаты не угадывай по адресу.

Если оба false (сообщение не о новой и не о закрытой заявке):
  - работа ещё идёт / не завершена, но заявка не новая ("еду забирать", "выехал", "в пути", "гружу")
  - сообщение не про эвакуацию вообще
  - непонятно
  В этом случае все остальные поля оставь null/пустыми, needs_review=false, status="unknown".

Правила (применяются только когда is_closed_job_report=true или is_new_job_request=true):
- Если информация не указана явно — возвращай null. НИКОГДА не придумывай данные.
- Госномер: нормализуй в верхний регистр, удали пробелы (напр. "н225рс797" → "Н225РС797")
- Марку пиши с заглавной буквы (напр. "джили" → "Geely", "бмв" → "BMW", "кия" → "Kia")
- Статус всегда "completed" (раз is_closed_job_report=true)
- needs_review: true если отсутствуют госномер, адрес или есть неоднозначность
- missing_required_fields: из списка ["license_plate","vehicle_make","pickup_address","destination"] — те что отсутствуют
- services и суммы (ВАЖНО, здесь чаще всего ошибаются):
  - В сообщении может быть НЕСКОЛЬКО отдельных денежных сумм: базовая эвакуация, доплата за
    лишние километры до/за МКАД, бустер, подкаты, ложная подача и т.п. КАЖДОЕ упомянутое число
    рублей — это отдельный элемент services, и total_amount_rub — это СУММА ВСЕХ этих чисел,
    ничего не теряй и не пропускай.
  - Пример: "эвакуация 4500 + 7км до мкада 900" → services: [{"name":"эвакуация","price_rub":4500},
    {"name":"7км до мкада","price_rub":900}], total_amount_rub: 5400 (4500+900, а НЕ 4500 и НЕ 900).
  - Пример: "Эвакуация +9км до мкад 5310" — здесь ОДНО число 5310, значит это уже готовая
    итоговая сумма за услугу с доплатой за км, total_amount_rub: 5310, НЕ прибавляй ничего сверху.
  - Если сумма явно не указана вообще — total_amount_rub: null, НЕ придумывай и не оценивай сумму.
- confidence: "high" если все основные поля найдены, "medium" если часть, "low" если мало данных

Верни JSON строго в этом формате:
{
  "is_closed_job_report": true или false,
  "is_new_job_request": true или false,
  "vehicle_make": "строка или null",
  "vehicle_model": "строка или null",
  "license_plate": "строка или null",
  "pickup_address": "строка или null",
  "destination": "строка или null",
  "pickup_lat": число или null,
  "pickup_lng": число или null,
  "destination_lat": число или null,
  "destination_lng": число или null,
  "service_until": "строка или null",
  "parking_lot": "строка или null",
  "status": "completed|in_progress|unknown",
  "services": [{"name": "строка", "price_rub": число или null}],
  "total_amount_rub": число или null,
  "confidence": "high|medium|low",
  "missing_required_fields": ["список"],
  "needs_review": true или false,
  "review_reason": "строка или null"
}"""


def _parse_json_from_response(text: str) -> dict:
    """Extract JSON from model response, handling markdown code blocks."""
    text = text.strip()
    # Strip markdown fences if present
    match = re.search(r"```(?:json)?\s*([\s\S]*?)```", text)
    if match:
        text = match.group(1).strip()
    data = json.loads(text)

    # YandexGPT occasionally wraps the single result object in a JSON list:
    # [{...}] instead of {...}. Treat that harmless format variation as the
    # same result instead of rejecting the whole message.
    if isinstance(data, list):
        if len(data) == 1 and isinstance(data[0], dict):
            data = data[0]
        else:
            raise ValueError("YandexGPT returned an unexpected JSON list")

    if not isinstance(data, dict):
        raise ValueError("YandexGPT response must be a JSON object")

    return data


def _unknown_job(reason: str) -> ExtractedJob:
    """Return a non-operational result without spending an AI request."""
    return ExtractedJob(
        is_closed_job_report=False,
        is_new_job_request=False,
        status=JobStatus.unknown,
        confidence=Confidence.high,
        missing_required_fields=[],
        needs_review=False,
        review_reason=reason,
    )


def _structured_request_overrides(message_text: str) -> dict:
    """Read explicit labelled fields deterministically; never geocode or guess."""
    updates: dict = {}

    pickup = re.search(
        r"(?im)^\s*Откуда\s*:\s*([+-]?\d{1,2}(?:[.,]\d+)?)\s*[,; ]\s*([+-]?\d{1,3}(?:[.,]\d+)?)\s*$",
        message_text,
    )
    if pickup:
        lat = float(pickup.group(1).replace(",", "."))
        lng = float(pickup.group(2).replace(",", "."))
        if -90 <= lat <= 90 and -180 <= lng <= 180:
            updates["pickup_lat"] = lat
            updates["pickup_lng"] = lng
            updates["pickup_address"] = f"{lat:.6f}, {lng:.6f}"
    else:
        labelled_pickup = re.search(r"(?im)^\s*Откуда\s*:\s*(.+?)\s*$", message_text)
        if labelled_pickup:
            updates["pickup_address"] = labelled_pickup.group(1).strip()

    destination = re.search(r"(?im)^\s*Куда\s*:\s*(.+?)\s*$", message_text)
    if destination:
        updates["destination"] = destination.group(1).strip()

    phone = re.search(r"(?im)^\s*Телефон\s*:\s*(.+?)\s*$", message_text)
    if phone:
        normalized = re.sub(r"[^\d+]", "", phone.group(1))
        if normalized:
            updates["customer_phone"] = normalized

    comment = re.search(r"(?is)(?:^|\n)\s*Комментарий\s*:\s*(.+)\Z", message_text)
    if comment:
        updates["customer_comment"] = comment.group(1).strip()[:600]

    service_until = re.search(
        r"(?im)^\s*(?:Сервис\s+работает\s+до|До\s+какого\s+времени|Время\s+работы)\s*:\s*(.+?)\s*$",
        message_text,
    )
    if service_until:
        updates["service_until"] = service_until.group(1).strip()

    return updates


def _vehicle_from_template(message_text: str, plate: str) -> tuple[str | None, str | None]:
    """Read the vehicle line immediately preceding the plate in partner templates."""
    lines = [line.strip() for line in message_text.splitlines() if line.strip()]
    plate_index = next(
        (index for index, line in enumerate(lines) if plate and plate in re.sub(r"\s+", "", line).upper()),
        None,
    )
    if plate_index is None or plate_index == 0:
        return None, None

    candidate = lines[plate_index - 1]
    if ":" in candidate or re.search(r"(?i)заявк|эвакуатор", candidate):
        return None, None

    parenthetical = re.match(r"^(.+?)\s*\(([^()]+)\)\s*$", candidate)
    if parenthetical:
        # Partner template commonly writes "MODEL (MAKE)", e.g. Coolray (BelGee).
        return parenthetical.group(2).strip(), parenthetical.group(1).strip()

    parts = candidate.split(maxsplit=1)
    return parts[0], parts[1] if len(parts) == 2 else None


def _local_extraction(message_text: str) -> tuple[ExtractedJob | None, str]:
    """Handle common messages locally; return None only when AI is genuinely useful."""
    from webhook.reporting_rules import (
        is_bot_generated_message,
        normalize_plate,
        parse_explicit_closed_report,
    )

    if is_bot_generated_message(message_text):
        return _unknown_job("bot reminder"), "local_reminder"

    closed = parse_explicit_closed_report(message_text)
    if closed is not None:
        return closed, "local_closed"

    explicit = _structured_request_overrides(message_text)
    lower = message_text.casefold()
    plate = normalize_plate(message_text)
    is_standard_request = bool(
        "pickup_address" in explicit
        and "destination" in explicit
        and plate
        and ("заяв" in lower or "эваку" in lower)
    )
    if is_standard_request:
        vehicle_make, vehicle_model = _vehicle_from_template(message_text, plate)
        missing = [
            field
            for field, value in (
                ("license_plate", plate),
                ("vehicle_make", vehicle_make),
                ("pickup_address", explicit.get("pickup_address")),
                ("destination", explicit.get("destination")),
            )
            if not value
        ]
        return ExtractedJob(
            is_closed_job_report=False,
            is_new_job_request=True,
            vehicle_make=vehicle_make,
            vehicle_model=vehicle_model,
            license_plate=plate,
            pickup_address=explicit.get("pickup_address"),
            destination=explicit.get("destination"),
            pickup_lat=explicit.get("pickup_lat"),
            pickup_lng=explicit.get("pickup_lng"),
            service_until=explicit.get("service_until"),
            customer_phone=explicit.get("customer_phone"),
            customer_comment=explicit.get("customer_comment"),
            status=JobStatus.unknown,
            confidence=Confidence.high if not missing else Confidence.medium,
            missing_required_fields=missing,
            needs_review=bool(missing),
            review_reason="Missing template fields" if missing else None,
        ), "local_new"

    # Acceptance/status messages are not new orders and must never be duplicated on the site.
    compact = " ".join(message_text.split()).casefold()
    if re.search(r"заявк\w*.*(?:принят\w*|в\s+работе|ещ[её]\s+не\s+закрыт\w*)", compact):
        return _unknown_job("job status notification"), "local_status"

    operational_markers = re.search(
        r"заяв|заказ|эваку|откуда|куда|закры\w*|выполн|дов[её]з|сдал|"
        r"отработал|ложн\w*\s+подач|мкад|госномер",
        lower,
    )
    if not operational_markers:
        return _unknown_job("non-operational chat message"), "local_irrelevant"

    return None, "yandex"


def extract_job(message_text: str, sender_name: str = "") -> ExtractedJob:
    """
    Extract structured job data from a raw employee message using YandexGPT.
    Returns ExtractedJob with null fields where information is missing.
    """
    local_job, source = _local_extraction(message_text)
    if local_job is not None:
        log.info(
            "EXTRACTED | source=%s | is_closed=%s | is_new=%s | plate=%s",
            source,
            local_job.is_closed_job_report,
            local_job.is_new_job_request,
            local_job.license_plate,
        )
        return local_job

    from webhook.config import settings

    if not settings.yandex_api_key:
        raise RuntimeError("YANDEX_API_KEY is not set in .env")
    if not settings.yandex_folder_id:
        raise RuntimeError("YANDEX_FOLDER_ID is not set in .env")

    user_text = f"Сообщение сотрудника"
    if sender_name:
        user_text += f" ({sender_name})"
    user_text += f":\n\n{message_text}"

    payload = {
        "modelUri": f"gpt://{settings.yandex_folder_id}/yandexgpt/latest",
        "completionOptions": {
            "stream": False,
            "temperature": 0.0,
            "maxTokens": 700,
        },
        "messages": [
            {"role": "system", "text": SYSTEM_PROMPT},
            {"role": "user", "text": user_text},
        ],
    }

    log.debug("Sending to YandexGPT: %r", message_text)

    with httpx.Client(verify=False, timeout=30) as client:
        resp = client.post(
            settings.YANDEX_LLM_URL,
            headers={
                "Authorization": f"Api-Key {settings.yandex_api_key}",
                "x-folder-id": settings.yandex_folder_id,
                "Content-Type": "application/json",
            },
            json=payload,
        )

    if resp.status_code != 200:
        raise RuntimeError(f"YandexGPT error {resp.status_code}: {resp.text[:300]}")

    raw_text = resp.json()["result"]["alternatives"][0]["message"]["text"]
    log.debug("YandexGPT raw response: %s", raw_text)

    data = _parse_json_from_response(raw_text)
    job = ExtractedJob.model_validate(data)
    explicit = _structured_request_overrides(message_text)
    if explicit:
        job = job.model_copy(update=explicit)

    log.info(
        "EXTRACTED | source=yandex | is_closed=%s | is_new=%s | plate=%s | make=%s %s | status=%s | confidence=%s | needs_review=%s | missing=%s",
        job.is_closed_job_report,
        job.is_new_job_request,
        job.license_plate,
        job.vehicle_make,
        job.vehicle_model or "",
        job.status,
        job.confidence,
        job.needs_review,
        job.missing_required_fields,
    )

    return job
