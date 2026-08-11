from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    max_bot_token: str
    max_webhook_secret: str
    max_webhook_url: str = ""
    log_level: str = "INFO"

    MAX_API_BASE: str = "https://platform-api2.max.ru"


settings = Settings()
