import asyncio
import logging
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

from app.config import settings
from app.services.google_oauth import GoogleOAuthTokenManager

logger = logging.getLogger(__name__)
KST = ZoneInfo("Asia/Seoul")


def _parse_datetime(dt_str: str) -> datetime | None:
    """날짜/시간 문자열을 datetime 객체로 파싱"""
    dt_str = dt_str.strip()
    if not dt_str:
        return None

    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M"):
        try:
            return datetime.strptime(dt_str, fmt).replace(tzinfo=KST)
        except ValueError:
            continue

    try:
        return datetime.fromisoformat(dt_str).replace(tzinfo=KST)
    except (ValueError, TypeError):
        return None


def _create_calendar_service():
    """Google Calendar 서비스 객체 생성 (동기)"""
    try:
        from google.oauth2.credentials import Credentials
        from googleapiclient.discovery import build
    except ImportError:
        logger.error("google-api-python-client not installed")
        return None, "google-api-python-client가 설치되지 않았습니다."

    token_manager = GoogleOAuthTokenManager()
    token = token_manager.load_token()
    if not token or "access_token" not in token:
        return None, "토큰이 없습니다. OAuth 인증이 필요합니다."

    # 만료 체크 및 갱신은 비동기라 여기서는 현재 토큰 사용
    creds = Credentials(
        token=token["access_token"],
        refresh_token=token.get("refresh_token"),
        token_uri=settings.GOOGLE_TOKEN_URI,
        client_id=settings.GOOGLE_CLIENT_ID,
        client_secret=settings.GOOGLE_CLIENT_SECRET,
    )

    service = build("calendar", "v3", credentials=creds)
    return service, ""


def _add_event_sync(title: str, start_time: str, end_time: str, description: str, location: str) -> dict:
    """동기적으로 캘린더 이벤트 추가"""
    service, error = _create_calendar_service()
    if not service:
        return {"b_success": False, "s_event_id": "", "s_error": error}

    start_dt = _parse_datetime(start_time)
    if not start_dt:
        return {"b_success": False, "s_event_id": "", "s_error": f"시작 시간 형식이 올바르지 않습니다: {start_time}"}

    if end_time:
        end_dt = _parse_datetime(end_time)
        if not end_dt:
            end_dt = start_dt + timedelta(hours=1)
    else:
        end_dt = start_dt + timedelta(hours=1)

    event = {
        "summary": title,
        "description": description,
        "location": location,
        "start": {"dateTime": start_dt.isoformat(), "timeZone": "Asia/Seoul"},
        "end": {"dateTime": end_dt.isoformat(), "timeZone": "Asia/Seoul"},
        "transparency": "opaque",
        "status": "confirmed",
        "visibility": "default",
        "reminders": {
            "useDefault": False,
            "overrides": [{"method": "popup", "minutes": 10}],
        },
    }

    try:
        created = service.events().insert(calendarId=settings.GOOGLE_CALENDAR_ID, body=event).execute()
        return {"b_success": True, "s_event_id": created.get("id", ""), "s_error": ""}
    except Exception as e:
        return {"b_success": False, "s_event_id": "", "s_error": f"캘린더 일정 추가 실패: {e}"}


async def add_event(title: str, start_time: str, end_time: str = "", description: str = "", location: str = "") -> dict:
    """비동기로 캘린더 이벤트 추가 (동기 API를 스레드에서 실행)"""
    # 먼저 토큰 갱신 (비동기)
    token_manager = GoogleOAuthTokenManager()
    if token_manager.is_expired():
        await token_manager.refresh_token()

    return await asyncio.to_thread(_add_event_sync, title, start_time, end_time, description, location)


def _log_calendar_debug(message: str, data=None) -> None:
    log_dir = Path(settings.LOG_DIR)
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / "calendar_debug.log"
    now_str = datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")
    entry = f"[{now_str}] {message}"
    if data is not None:
        entry += f"\n{data}"
    entry += "\n"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(entry)


def _log_calendar_error(content: str, alarm_date: str, error: str) -> None:
    log_dir = Path(settings.LOG_DIR)
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / "calendar_error.log"
    now_str = datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")
    entry = f"[{now_str}] 캘린더 추가 실패\n내용: {content}\n시간: {alarm_date}\n에러: {error}\n\n"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(entry)
