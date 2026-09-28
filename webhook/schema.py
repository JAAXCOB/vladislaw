"""
Structured schema for an extracted evacuation job.
Fields are nullable — AI must not invent missing information.
"""
from __future__ import annotations

from enum import Enum
from typing import Optional

from pydantic import BaseModel, Field, model_validator


class JobStatus(str, Enum):
    completed = "completed"
    in_progress = "in_progress"
    unknown = "unknown"


class Confidence(str, Enum):
    high = "high"
    medium = "medium"
    low = "low"


class ServiceItem(BaseModel):
    name: str = Field(min_length=1, max_length=200)
    price_rub: Optional[int] = Field(None, ge=0, le=50_000_000)


class ExtractedJob(BaseModel):
    """
    Structured data extracted from a single employee message.
    Every field is Optional — null means "not mentioned in the message".
    """
    is_closed_job_report: bool = Field(
        False,
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
    vehicle_make: Optional[str] = Field(None, max_length=100, description="Марка автомобиля, напр. 'Geely', 'BMW'")
    vehicle_model: Optional[str] = Field(None, max_length=100, description="Модель, напр. '530', 'Rio'")
    license_plate: Optional[str] = Field(None, max_length=32, description="Госномер в верхнем регистре, напр. 'Н225РС797'")
    pickup_address: Optional[str] = Field(None, max_length=500, description="Адрес откуда забрали автомобиль")
    destination: Optional[str] = Field(None, max_length=500, description="Куда отвезли — адрес или название стоянки")
    pickup_lat: Optional[float] = Field(None, ge=-90, le=90, description="Широта точки подачи")
    pickup_lng: Optional[float] = Field(None, ge=-180, le=180, description="Долгота точки подачи")
    destination_lat: Optional[float] = Field(None, ge=-90, le=90, description="Широта конечной точки")
    destination_lng: Optional[float] = Field(None, ge=-180, le=180, description="Долгота конечной точки")
    service_until: Optional[str] = Field(
        None,
        max_length=200,
        description="До какого времени работает сервис/стоянка; только текст из заявки",
    )
    customer_phone: Optional[str] = Field(None, max_length=32, description="Телефон клиента из новой заявки")
    customer_comment: Optional[str] = Field(None, max_length=600, description="Комментарий и важные примечания из новой заявки")
    parking_lot: Optional[str] = Field(None, max_length=200, description="Номер/название спецстоянки, напр. 'Спецстоянка №3'")
    status: JobStatus = Field(JobStatus.unknown, description="Статус заявки")
    services: list[ServiceItem] = Field(default_factory=list, max_length=30, description="Перечень услуг с ценами")
    total_amount_rub: Optional[int] = Field(None, ge=0, le=50_000_000, description="Общая сумма в рублях")
    confidence: Confidence = Field(Confidence.low, description="Уверенность в качестве извлечения")
    missing_required_fields: list[str] = Field(
        default_factory=list,
        max_length=16,
        description="Список обязательных полей, которые отсутствуют в сообщении"
    )
    needs_review: bool = Field(True, description="True если требуется проверка человеком")
    review_reason: Optional[str] = Field(None, max_length=500, description="Причина для проверки")

    @model_validator(mode="after")
    def validate_event_invariants(self) -> "ExtractedJob":
        if self.is_closed_job_report and self.is_new_job_request:
            raise ValueError("a job cannot be both new and closed")
        allowed_missing = {"license_plate", "vehicle_make", "pickup_address", "destination"}
        if any(field not in allowed_missing for field in self.missing_required_fields):
            raise ValueError("missing_required_fields contains an unknown field")
        if self.is_closed_job_report:
            self.status = JobStatus.completed
        elif self.status == JobStatus.completed:
            raise ValueError("completed status requires a closed job report")
        if self.total_amount_rub is not None:
            known_total = sum(item.price_rub for item in self.services if item.price_rub is not None)
            if known_total and known_total != self.total_amount_rub:
                raise ValueError("total_amount_rub must equal the sum of service prices")
        return self
