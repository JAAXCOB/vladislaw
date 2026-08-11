"""
Phase 2 — AI extraction of structured job data from employee messages.
Uses YandexGPT via REST API with JSON output parsing.
"""
from __future__ import annotations

import json
import logging
import re

import httpx

from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.extractor")

SYSTEM_PROMPT = """Ты — система извлечения данных для бизнеса по эвакуации (буксировке) автомобилей в России.
Тебе приходят сообщения из рабочего группового чата в мессенджере. Большинство — отчёты
сотрудников-водителей о выполненных работах, но встречаются и посторонние сообщения:
приветствия, вопросы, шутки, обсуждения, не связанные с конкретной эвакуацией.

Твоя задача — извлечь структурированные данные и вернуть ТОЛЬКО валидный JSON без пояснений.

ПЕРВЫМ ДЕЛОМ определи is_job_report:
- true — если сообщение является отчётом о выполненной или текущей работе по эвакуации
  (упоминает машину, номер, адрес, услугу, сумму и т.п.)
- false — если сообщение НЕ является отчётом о работе (приветствие, вопрос, флуд, обсуждение
  постороннего). В этом случае все остальные поля оставь null/пустыми, needs_review=false.

Правила (применяются только когда is_job_report=true):
- Если информация не указана явно — возвращай null. НИКОГДА не придумывай данные.
- Госномер: нормализуй в верхний регистр, удали пробелы (напр. "н225рс797" → "Н225РС797")
- Марку пиши с заглавной буквы (напр. "джили" → "Geely", "бмв" → "BMW", "кия" → "Kia")
- Статус "completed": если написано "закрыта", "выполнено", "сдал", "поставил", "отвёз", "забрал"
- Статус "in_progress": если работа ещё не завершена
- Статус "unknown": если непонятно
- needs_review: true если отсутствуют госномер, адрес или есть неоднозначность
- missing_required_fields: из списка ["license_plate","vehicle_make","pickup_address","destination"] — те что отсутствуют
- services: массив объектов {"name": "...", "price_rub": число или null}
- total_amount_rub: сумма всех услуг если цены указаны, иначе null
- confidence: "high" если все основные поля найдены, "medium" если часть, "low" если мало данных

Верни JSON строго в этом формате:
{
  "is_job_report": true или false,
  "vehicle_make": "строка или null",
  "vehicle_model": "строка или null",
  "license_plate": "строка или null",
  "pickup_address": "строка или null",
  "destination": "строка или null",
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
    return json.loads(text)


def extract_job(message_text: str, sender_name: str = "") -> ExtractedJob:
    """
    Extract structured job data from a raw employee message using YandexGPT.
    Returns ExtractedJob with null fields where information is missing.
    """
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
            "maxTokens": 1000,
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

    log.info(
        "EXTRACTED | is_job=%s | plate=%s | make=%s %s | status=%s | confidence=%s | needs_review=%s | missing=%s",
        job.is_job_report,
        job.license_plate,
        job.vehicle_make,
        job.vehicle_model or "",
        job.status,
        job.confidence,
        job.needs_review,
        job.missing_required_fields,
    )

    return job
