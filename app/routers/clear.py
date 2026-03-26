from fastapi import APIRouter, Form

from app.services.mattermost import (
    generate_clear_message,
    get_webhook_url_by_channel_id,
    send_mattermost_message,
)

router = APIRouter(prefix="/clear", tags=["clear"])


async def _do_clear(channel_id: str, lines: int) -> dict:
    """채널로 클리어 메시지를 전송하는 공통 로직"""
    if not channel_id:
        return {"response_type": "ephemeral", "text": "\u274c 채널 정보를 찾을 수 없습니다."}

    webhook_result = get_webhook_url_by_channel_id(channel_id)
    if not webhook_result["b_success"]:
        return {"response_type": "ephemeral", "text": f"\u274c {webhook_result['s_error']}"}

    msg = generate_clear_message(lines)
    sent = await send_mattermost_message(webhook_result["s_webhook_url"], msg)

    if sent:
        return {"response_type": "ephemeral", "text": ""}
    return {"response_type": "ephemeral", "text": "\u274c 메시지 전송에 실패했습니다."}


@router.post("")
async def clear_default(channel_id: str = Form("")):
    """기본 클리어"""
    return await _do_clear(channel_id, 50)


@router.post("/quiet")
async def clear_quiet():
    """본인에게만 보이는 클리어"""
    msg = generate_clear_message(50)
    return {"response_type": "ephemeral", "text": msg}


@router.post("/simple")
async def clear_simple(channel_id: str = Form("")):
    return await _do_clear(channel_id, 20)


@router.post("/max")
async def clear_max(channel_id: str = Form("")):
    return await _do_clear(channel_id, 100)
