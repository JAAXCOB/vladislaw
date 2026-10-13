from __future__ import annotations

from pathlib import Path
from urllib.parse import urlparse

from pydantic import Field, SecretStr, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


def _is_https_or_loopback(url: str) -> bool:
    parsed = urlparse(url)
    return parsed.scheme == "https" or (
        parsed.scheme == "http" and parsed.hostname in {"127.0.0.1", "localhost", "::1"}
    )


class ControllerSettings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env.ai-controller",
        env_file_encoding="utf-8",
        env_prefix="AVR_AI_",
        extra="ignore",
    )

    data_dir: Path = Path("data/ai-controller")
    poll_interval_seconds: int = 60
    request_timeout_seconds: float = 8.0
    log_level: str = "INFO"

    site_health_url: str
    max_health_url: str = "http://127.0.0.1:8000/health"
    osrm_base_url: str
    osrm_probe_lon: float = 37.6176
    osrm_probe_lat: float = 55.7558

    audit_hmac_key: SecretStr
    audit_retention_days: int = 90

    paid_api_enabled: bool = False
    daily_budget_minor: int = 0
    monthly_budget_minor: int = 0
    per_request_budget_minor: int = 0

    max_control_enabled: bool = False
    max_control_token: SecretStr = SecretStr("")
    max_control_chat_id: str = ""
    max_control_user_ids: str = ""
    max_control_api_base: str = "https://platform-api2.max.ru"
    max_control_poll_timeout_seconds: int = 20
    max_control_command_ttl_seconds: int = 180

    yandex_mail_enabled: bool = False
    yandex_imap_host: str = "imap.yandex.ru"
    yandex_imap_port: int = 993
    yandex_mailbox: str = ""
    yandex_app_password: SecretStr = SecretStr("")

    gmail_enabled: bool = False
    gmail_client_id: str = ""
    gmail_client_secret: SecretStr = SecretStr("")
    gmail_refresh_token: SecretStr = SecretStr("")
    gmail_token_url: str = "https://oauth2.googleapis.com/token"
    gmail_api_base: str = "https://gmail.googleapis.com/gmail/v1"

    mail_sample_limit: int = 10

    @field_validator("poll_interval_seconds")
    @classmethod
    def validate_poll_interval(cls, value: int) -> int:
        if not 10 <= value <= 3600:
            raise ValueError("poll interval must be between 10 and 3600 seconds")
        return value

    @field_validator("audit_hmac_key")
    @classmethod
    def validate_audit_key(cls, value: SecretStr) -> SecretStr:
        secret = value.get_secret_value().strip()
        if len(secret) < 32:
            raise ValueError("audit HMAC key must contain at least 32 characters")
        if secret == "replace-with-at-least-32-random-characters":
            raise ValueError("replace the example audit HMAC key before starting the controller")
        return value

    @field_validator("mail_sample_limit")
    @classmethod
    def validate_mail_limit(cls, value: int) -> int:
        if not 1 <= value <= 50:
            raise ValueError("mail sample limit must be between 1 and 50")
        return value

    @model_validator(mode="after")
    def validate_security_policy(self) -> "ControllerSettings":
        for name, value in (
            ("site_health_url", self.site_health_url),
            ("max_health_url", self.max_health_url),
            ("osrm_base_url", self.osrm_base_url),
            ("max_control_api_base", self.max_control_api_base),
            ("gmail_token_url", self.gmail_token_url),
            ("gmail_api_base", self.gmail_api_base),
        ):
            if not _is_https_or_loopback(value):
                raise ValueError(f"{name} must use HTTPS or a loopback HTTP address")

        budgets = (
            self.daily_budget_minor,
            self.monthly_budget_minor,
            self.per_request_budget_minor,
        )
        if any(value < 0 for value in budgets):
            raise ValueError("budgets cannot be negative")
        if self.paid_api_enabled and any(value <= 0 for value in budgets):
            raise ValueError("all budgets must be positive before paid APIs can be enabled")

        if self.max_control_enabled:
            if not self.max_control_token.get_secret_value().strip():
                raise ValueError("MAX control token is required")
            if not self.max_control_chat_id.lstrip("-").isdigit():
                raise ValueError("MAX control chat id must be numeric")
            if not self.allowed_control_user_ids:
                raise ValueError("at least one MAX control user id is required")

        if self.yandex_mail_enabled and not (
            self.yandex_mailbox and self.yandex_app_password.get_secret_value()
        ):
            raise ValueError("Yandex mailbox and app password are required")
        if self.gmail_enabled and not (
            self.gmail_client_id
            and self.gmail_client_secret.get_secret_value()
            and self.gmail_refresh_token.get_secret_value()
        ):
            raise ValueError("Gmail OAuth credentials are required")
        return self

    @property
    def allowed_control_user_ids(self) -> frozenset[str]:
        values = {item.strip() for item in self.max_control_user_ids.split(",") if item.strip()}
        if any(not item.isdigit() for item in values):
            raise ValueError("MAX control user ids must be numeric")
        return frozenset(values)

    def prepare_directories(self) -> None:
        self.data_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
