from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    # MySQL
    MYSQL_HOST: str = "localhost"
    MYSQL_PORT: int = 3306
    MYSQL_USER: str = "root"
    MYSQL_PASSWORD: str = ""
    MYSQL_DATABASE: str = "wp_home_roondal"

    # PostgreSQL
    PG_HOST: str = "localhost"
    PG_PORT: int = 5432
    PG_USER: str = "postgres"
    PG_PASSWORD: str = ""
    PG_DATABASE: str = "mattermost"

    # Perplexity AI
    PERPLEXITY_API_KEY: str = ""
    PERPLEXITY_MODEL: str = "sonar-reasoning-pro"

    # Google OAuth
    GOOGLE_CLIENT_ID: str = ""
    GOOGLE_CLIENT_SECRET: str = ""
    GOOGLE_AUTH_URI: str = "https://accounts.google.com/o/oauth2/auth"
    GOOGLE_TOKEN_URI: str = "https://oauth2.googleapis.com/token"
    GOOGLE_REDIRECT_URI: str = "https://api.roondal.co.kr/oauth/callback"
    GOOGLE_CALENDAR_ID: str = "candrew9414@gmail.com"
    GOOGLE_TOKEN_FILE: str = "./tmp/google_oauth_token.json"
    GOOGLE_SERVICE_ACCOUNT_FILE: str = "./config/calander-api-account.json"

    # Mattermost
    MATTERMOST_CHANNEL_LIST_FILE: str = "./mattermost_channel_list.txt"
    MATTERMOST_BOT_NAME: str = ""
    MATTERMOST_ICON_URL: str = ""

    # App
    APP_TIMEZONE: str = "Asia/Seoul"
    LOG_DIR: str = "./logs"

    @property
    def mysql_url(self) -> str:
        return (
            f"mysql+asyncmy://{self.MYSQL_USER}:{self.MYSQL_PASSWORD}"
            f"@{self.MYSQL_HOST}:{self.MYSQL_PORT}/{self.MYSQL_DATABASE}"
        )

    @property
    def pg_url(self) -> str:
        return (
            f"postgresql+asyncpg://{self.PG_USER}:{self.PG_PASSWORD}"
            f"@{self.PG_HOST}:{self.PG_PORT}/{self.PG_DATABASE}"
        )


settings = Settings()
