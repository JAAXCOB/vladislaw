"""
Phase 2 — AI extraction of structured job data from employee messages.
Uses OpenAI with structured output (JSON mode + Pydantic validation).
"""
from __future__ import annotations

import json
import logging

from openai import OpenAI

from webhook.schema import ExtractedJob

log = logging.getLogger("max_webhook.extractor")

SYSTEM_PROMPT = """Ты — система извлечения данных для бизнеса по эвакуации (буксировке) автомобилей в России.
Тебе приходят короткие сообщения от сотрудников-водителей в мессенджере о выполненных работах.
Твоя задача — извлечь структурированные данные из сообщения и вернуть JSON.

Правила извлечения:
- Если информация не указана явно — возвращай null. НИКОГДА не придумывай данные.
- Госномер: нормализуй в верхний регистр, удали пробелы (напр. "н225рс797" → "Н225РС797")
- Марку и модель пиши с заглавной буквы (напр. "джили" → "Geely", "бмв" → "BMW")
- Услуги: ищи упоминания работ с ценами (эвакуация, подкаты, хранение и т.д.)
- Статус "completed": если написано "закрыта", "выполнено", "сдал", "поставил", "отвёз" и т.п.
- Статус "in_progress": если работа ещё не завершена
- needs_review: true если есть неоднозначность, противоречие или отсутствуют госномер/адрес
- missing_required_fields: перечисли поля из ["license_plate", "vehicle_make", "pickup_address", "destination"] которые отсутствуют

Обязательные поля (если отсутствуют — добавь в missing_required_fields):
- license_plate (госномер)
- vehicle_make (марка)
- pickup_address (адрес забора)
- destination или parking_lot (куда отвезли)

Примеры сообщений:
"Забрал BMW 530, госномер А123ВС777, с ул. Ленина 15, поставил на спецстоянку №3. Работа выполнена."
"Заявка закрыта джили н225рс797 эвакуация 4500 подкаты 1300"
"BMW забрал, отвез на стоянку."
"""


def extract_job(message_text: str, sender_name: str = "") -> ExtractedJob:
    """
    Extract structured job data from a raw employee message.
    Returns ExtractedJob with null fields where information is missing.
    """
    from webhook.config import settings

    if not settings.openai_api_key:
        raise RuntimeError("OPENAI_API_KEY is not set in .env")

    client = OpenAI(api_key=settings.openai_api_key)

    user_content = f"Сообщение сотрудника"
    if sender_name:
        user_content += f" ({sender_name})"
    user_content += f":\n\n{message_text}"

    log.debug("Sending to OpenAI: %r", message_text)

    response = client.chat.completions.create(
        model="gpt-4o-mini",
        response_format={"type": "json_object"},
        messages=[
            {"role": "system", "content": SYSTEM_PROMPT},
            {"role": "user", "content": user_content},
        ],
        temperature=0,
    )

    raw = response.choices[0].message.content
    log.debug("OpenAI raw response: %s", raw)

    data = json.loads(raw)
    job = ExtractedJob.model_validate(data)

    log.info(
        "EXTRACTED | plate=%s | make=%s %s | status=%s | confidence=%s | needs_review=%s | missing=%s",
        job.license_plate,
        job.vehicle_make,
        job.vehicle_model or "",
        job.status,
        job.confidence,
        job.needs_review,
        job.missing_required_fields,
    )

    return job
