"""
Structured schema for an extracted evacuation job.
Fields are nullable — AI must not invent missing information.
"""
from __future__ import annotations

from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field


class JobStatus(str, Enum):
    completed = "completed"
    in_progress = "in_progress"
    unknown = "unknown"


class Confidence(str, Enum):
    high = "high"
    medium = "medium"
    low = "low"


class ServiceItem(BaseModel):
    name: str
    price_rub: Optional[int] = None


class ExtractedJob(BaseModel):
    """
    Structured data extracted from a single employee message.
    Every field is Optional — null means "not mentioned in the message".
    """
    is_closed_job_report: bool = Field(
        True,
        description="True только если в сообщении явно сказано, что заявка ЗАКРЫТА/ВЫПОЛНЕНА "
                     "(в любой формулировке, даже разговорной). False для всего остального: "
                     "не по теме, заявка ещё не закрыта, неясно."
    )
    is_new_job_request: bool = Field(
        False,
        description="True если сообщение — новая заявка на эвакуацию, которую нужно принять "
                     "в работу (обычно 'Примите заявку', 'Новая заявка' с адресом/машиной/номером). "
                     "Взаимоисключающе с is_closed_job_report — сообщение не может быть и тем и другим."
    )
    vehicle_make: Optional[str] = Field(None, description="Марка автомобиля, напр. 'Geely', 'BMW'")
    vehicle_model: Optional[str] = Field(None, description="Модель, напр. '530', 'Rio'")
    license_plate: Optional[str] = Field(None, description="Госномер в верхнем регистре, напр. 'Н225РС797'")
    pickup_address: Optional[str] = Field(None, description="Адрес откуда забрали автомобиль")
    destination: Optional[str] = Field(None, description="Куда отвезли — адрес или название стоянки")
    parking_lot: Optional[str] = Field(None, description="Номер/название спецстоянки, напр. 'Спецстоянка №3'")
    status: JobStatus = Field(JobStatus.unknown, description="Статус заявки")
    services: list[ServiceItem] = Field(default_factory=list, description="Перечень услуг с ценами")
    total_amount_rub: Optional[int] = Field(None, description="Общая сумма в рублях")
    confidence: Confidence = Field(Confidence.low, description="Уверенность в качестве извлечения")
    missing_required_fields: list[str] = Field(
        default_factory=list,
        description="Список обязательных полей, которые отсутствуют в сообщении"
    )
    needs_review: bool = Field(True, description="True если требуется проверка человеком")
    review_reason: Optional[str] = Field(None, description="Причина для проверки")
