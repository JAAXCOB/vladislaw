from pydantic import field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    max_bot_token: str
    max_webhook_secret: str
    max_webhook_url: str = ""
    log_level: str = "INFO"
    openai_api_key: str = ""
    yandex_api_key: str = ""
    yandex_folder_id: str = ""
    excel_file_path: str = ""
    payroll_file_path: str = ""
    max_chat_id: str = ""
    enable_job_reminders: bool = False
    av_rescue_api_url: str = ""
    av_rescue_api_key: str = ""
    av_rescue_sync_queue_path: str = "data/av_rescue_sync_queue.json"
    event_inbox_path: str = "data/event_inbox"
    event_retry_base_seconds: int = 15
    event_max_attempts: int = 10
    event_poll_seconds: int = 5

    MAX_API_BASE: str = "https://platform-api2.max.ru"
    YANDEX_LLM_URL: str = "https://llm.api.cloud.yandex.net/foundationModels/v1/completion"

    @field_validator("max_webhook_secret")
    @classmethod
    def validate_webhook_secret(cls, value: str) -> str:
        if not 5 <= len(value) <= 256 or any(
            character not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-"
            for character in value
        ):
            raise ValueError("MAX_WEBHOOK_SECRET must be 5-256 allowed characters")
        return value

    @field_validator("max_bot_token")
    @classmethod
    def validate_bot_token(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("MAX_BOT_TOKEN cannot be empty")
        return value

    @field_validator("max_chat_id")
    @classmethod
    def validate_chat_allowlist(cls, value: str) -> str:
        entries = [item.strip() for item in value.split(",") if item.strip()]
        if not entries or any(not item.lstrip("-").isdigit() for item in entries):
            raise ValueError("MAX_CHAT_ID must contain one or more numeric chat IDs")
        return ",".join(entries)

    @model_validator(mode="after")
    def validate_security_settings(self) -> "Settings":
        for name, value in (
            ("MAX_API_BASE", self.MAX_API_BASE),
            ("YANDEX_LLM_URL", self.YANDEX_LLM_URL),
        ):
            if not value.startswith("https://"):
                raise ValueError(f"{name} must use HTTPS")
        if self.max_webhook_url and not self.max_webhook_url.startswith("https://"):
            raise ValueError("MAX_WEBHOOK_URL must use HTTPS")
        if self.av_rescue_api_url and not self.av_rescue_api_url.startswith("https://"):
            raise ValueError("AV_RESCUE_API_URL must use HTTPS")
        if not 1 <= self.event_max_attempts <= 100:
            raise ValueError("EVENT_MAX_ATTEMPTS must be between 1 and 100")
        return self

    @property
    def allowed_chat_ids(self) -> frozenset[str]:
        return frozenset(item.strip() for item in self.max_chat_id.split(",") if item.strip())


settings = Settings()
