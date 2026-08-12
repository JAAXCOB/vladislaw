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

    MAX_API_BASE: str = "https://platform-api2.max.ru"
    YANDEX_LLM_URL: str = "https://llm.api.cloud.yandex.net/foundationModels/v1/completion"


settings = Settings()
