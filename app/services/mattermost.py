import logging
import threading
from pathlib import Path

import httpx

from app.config import settings

logger = logging.getLogger(__name__)

# 채널 목록 캐시 (스레드 안전)
_channel_webhooks: dict[str, str] | None = None
_channel_webhooks_lock = threading.Lock()


def load_channel_webhooks() -> dict[str, str]:
    """mattermost_channel_list.txt 파일에서 채널ID→웹훅URL 매핑을 로드"""
    global _channel_webhooks
    if _channel_webhooks is not None:
        return _channel_webhooks

    with _channel_webhooks_lock:
        # Double-checked locking
        if _channel_webhooks is not None:
            return _channel_webhooks

        result: dict[str, str] = {}
        path = Path(settings.MATTERMOST_CHANNEL_LIST_FILE)
        if not path.exists():
            logger.warning("mattermost_channel_list.txt not found: %s", path)
            _channel_webhooks = result
            return result

        for line in path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" in line:
                channel_id, webhook_url = line.split("=", 1)
                channel_id = channel_id.strip()
                webhook_url = webhook_url.strip()
                if channel_id and webhook_url:
                    result[channel_id] = webhook_url

        _channel_webhooks = result
        return result


def get_webhook_url_by_channel_id(channel_id: str) -> dict:
    """채널 ID로 웹훅 URL 조회"""
    webhooks = load_channel_webhooks()
    if channel_id in webhooks:
        return {"b_success": True, "s_webhook_url": webhooks[channel_id], "s_error": ""}
    return {
        "b_success": False,
        "s_webhook_url": "",
        "s_error": f"채널 ID({channel_id})에 해당하는 webhook URL을 찾을 수 없습니다.",
    }


async def send_mattermost_message(
    webhook_url: str, message: str, bot_name: str = "", icon_url: str = ""
) -> bool:
    """Mattermost 웹훅으로 메시지 전송"""
    payload: dict = {"text": message}
    if bot_name:
        payload["username"] = bot_name
    if icon_url:
        payload["icon_url"] = icon_url

    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.post(webhook_url, json=payload)
            return 200 <= resp.status_code < 300
    except Exception as e:
        logger.error("Mattermost webhook failed: %s", e)
        return False


async def send_response_url(url: str, response_data: dict) -> bool:
    """Mattermost response_url로 후속 메시지 전송"""
    if not url:
        return False

    try:
        async with httpx.AsyncClient(timeout=10, follow_redirects=True) as client:
            resp = await client.post(url, json=response_data)
            return 200 <= resp.status_code < 300
    except Exception as e:
        logger.error("response_url send failed: url=%s error=%s", url, e)
        return False


def generate_clear_message(lines: int = 50) -> str:
    """클리어 효과 메시지 생성 (zero-width space 사용)"""
    invisible = "\u200b"
    parts = ["\u2800"]  # Braille blank
    parts.extend([invisible] * lines)
    parts.append("---")
    parts.append("\U0001f9f9 *화면이 정리되었습니다.*")
    return "\n".join(parts)
