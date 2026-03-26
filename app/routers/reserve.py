import logging
import re
from random import randint

from fastapi import APIRouter, BackgroundTasks, Form
from sqlalchemy import select, update

from app.database import get_mysql_session, get_pg_session
from app.models.alarm import G5Alarm
from app.services.alarm_content import normalize_alarm_content
from app.services.google_calendar import _log_calendar_debug, _log_calendar_error, add_event
from app.services.mattermost import get_webhook_url_by_channel_id, send_response_url
from app.services.perplexity import call_perplexity_api, process_perplexity_response
from app.utils.datetime_helpers import build_alarm_datetime, format_datetime

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/reserve", tags=["reserve"])


# ─── 주소 추출/제거 ────────────────────────────────────

def _extract_address(text_input: str) -> str:
    """사용자 입력에서 주소 추출"""
    text_input = text_input.strip()
    if not text_input:
        return ""

    # "주소는 ..." / "주소: ..." 패턴 (문장 끝)
    m = re.search(r"주소\s*(는|은)?\s*[:：]?\s*([^,\n]+)$", text_input)
    if m:
        return _normalize_address(m.group(2))

    # 문장 중간 (콤마 구분)
    m = re.search(r"주소\s*(는|은)?\s*[:：]?\s*([^,\n]+)", text_input)
    if m:
        return _normalize_address(m.group(2))

    return ""


def _normalize_address(address: str) -> str:
    """주소 텍스트 정규화"""
    address = address.strip().strip("\"'`").rstrip(" .,!?").strip()
    if not address:
        return ""
    # 요청/지시 말투 제거
    address = re.sub(r"\s*(로|으로)\s*(해줘요|해줘|해주세요|해\s*주세요|부탁해요|부탁해)\s*$", "", address)
    address = re.sub(r"\s*(해줘요|해줘|해주세요|해\s*주세요|부탁해요|부탁해)\s*$", "", address)
    address = address.strip()
    # 종결 말투 제거
    address = re.sub(r"\s*(야|이야|다|임|입니다|이에요|예요)\s*$", "", address)
    return address.strip().strip("\"'`").rstrip(" .,!?").strip()


def _remove_address(text_input: str) -> str:
    """주소 문구 제거 (Perplexity 입력용)"""
    text_input = text_input.strip()
    if not text_input:
        return ""
    text_input = re.sub(r"\s*,?\s*주소\s*(는|은)?\s*[:：]?\s*[^,\n]+\s*$", "", text_input)
    text_input = re.sub(r"주소\s*(는|은)?\s*[:：]?\s*[^,\n]+", "", text_input)
    return text_input.strip(" ,").strip()


# ─── 예약 완료 파싱 ────────────────────────────────────

def _parse_complete_request(text_input: str) -> dict:
    """예약 완료 요청 파싱"""
    m = re.search(r"예약번호\s*(\d{4})\s*번?\s*(완료|완료처리|취소|삭제|삭제요청)", text_input)
    if m:
        return {"b_complete": True, "i_cron_num": int(m.group(1))}

    m = re.search(r"(\d{4})\s*번?\s*예약\s*(완료|완료처리|취소|삭제)", text_input)
    if m:
        return {"b_complete": True, "i_cron_num": int(m.group(1))}

    return {"b_complete": False, "i_cron_num": 0}


# ─── DB 조작 ────────────────────────────────────────

async def _complete_alarm(cron_num: int) -> dict:
    """예약번호로 알림 완료 처리"""
    async with get_mysql_session() as session:
        stmt = update(G5Alarm).where(G5Alarm.cron_num == cron_num).values(complete_flag="Y")
        result = await session.execute(stmt)
        await session.commit()
        if result.rowcount == 0:
            return {"b_success": False, "s_error": "해당 예약번호를 찾을 수 없습니다."}
        return {"b_success": True, "s_error": ""}


async def _generate_unique_cron_num() -> dict:
    """중복 없는 4자리 예약번호 생성"""
    async with get_mysql_session() as session:
        for _ in range(30):
            candidate = randint(1000, 9999)
            result = await session.execute(
                select(G5Alarm.cron_num).where(G5Alarm.cron_num == candidate)
            )
            if result.first() is None:
                return {"b_success": True, "i_cron_num": candidate, "s_error": ""}
    return {"b_success": False, "i_cron_num": 0, "s_error": "예약번호 생성에 실패했습니다."}


async def _get_latest_mattermost_channel_id() -> dict:
    """PostgreSQL에서 최신 채널 ID 조회"""
    try:
        async with get_pg_session() as session:
            result = await session.execute(
                text("SELECT channelid FROM commandwebhooks ORDER BY createat DESC LIMIT 1")
            )
            row = result.first()
            if not row:
                return {"b_success": False, "s_channel_id": "", "s_error": "commandwebhooks에 데이터가 없습니다."}
            return {"b_success": True, "s_channel_id": row[0], "s_error": ""}
    except Exception as e:
        return {"b_success": False, "s_channel_id": "", "s_error": f"PostgreSQL 연결 실패: {e}"}


async def _insert_alarm_record(content: str, address: str, alarm_date: str) -> dict:
    """g5_alarm 테이블에 알림 예약 저장"""
    # 예약번호 생성
    cron_result = await _generate_unique_cron_num()
    if not cron_result["b_success"]:
        return {"b_success": False, "s_error": cron_result["s_error"], "i_cron_num": 0}
    cron_num = cron_result["i_cron_num"]

    # 채널 ID 조회
    channel_result = await _get_latest_mattermost_channel_id()
    if not channel_result["b_success"]:
        return {"b_success": False, "s_error": channel_result["s_error"], "i_cron_num": 0}
    channel_id = channel_result["s_channel_id"]

    # 웹훅 URL 조회
    webhook_result = get_webhook_url_by_channel_id(channel_id)
    if not webhook_result["b_success"]:
        return {"b_success": False, "s_error": webhook_result["s_error"], "i_cron_num": 0}
    webhook_url = webhook_result["s_webhook_url"]

    # DB 저장
    try:
        async with get_mysql_session() as session:
            alarm = G5Alarm(
                cron_num=cron_num,
                content=content,
                address=address,
                alarm_date=alarm_date,
                complete_flag="N",
                channelid=channel_id,
                webhook_url=webhook_url,
            )
            session.add(alarm)
            await session.commit()
    except Exception as e:
        return {"b_success": False, "s_error": f"알림 저장에 실패했습니다: {e}", "i_cron_num": 0}

    _log_calendar_debug("DB 저장 성공, 캘린더 추가 시작", {
        "content": content, "alarm_date": alarm_date, "cron_num": cron_num,
    })

    # Google Calendar 일정 추가
    try:
        cal_result = await add_event(
            title=content,
            start_time=alarm_date,
            description="\U0001f514 Mattermost 예약 알림으로 등록된 일정입니다.",
            location=address,
        )
        _log_calendar_debug("캘린더 추가 완료", cal_result)
        if not cal_result["b_success"]:
            _log_calendar_error(content, alarm_date, cal_result["s_error"])
    except Exception as e:
        _log_calendar_error(content, alarm_date, str(e))

    return {"b_success": True, "s_error": "", "i_cron_num": cron_num}


# ─── 성공 메시지 ────────────────────────────────────

def _build_success_message(parsed: dict, user_name: str, cron_num: int) -> str:
    date_str = parsed.get("date", "")
    time_str = parsed.get("time", "")
    content = parsed.get("content", "")
    formatted = format_datetime(date_str, time_str)

    msg = f"\u2705 **{user_name}**님이 알림 예약을 등록하셨습니다.\n\n"
    msg += f"- **날짜** : {date_str}\n"
    msg += f"- **시간** : {time_str}\n"
    if cron_num:
        msg += f"- **예약번호** : {cron_num}\n"
    msg += f"- **내용** : {content}\n\n"
    msg += f"\U0001f4e2 **{formatted}**에 알림 보내드리겠습니다."
    return msg


# ─── 백그라운드 예약 처리 ────────────────────────────

async def _process_reservation(
    text_input: str, response_url: str, user_name: str
):
    """백그라운드에서 예약 처리 (Perplexity API 호출 포함)"""
    # 주소 추출 & 제거
    extracted_address = _extract_address(text_input)
    text_for_ai = _remove_address(text_input)

    # Perplexity API 호출
    try:
        api_result = await call_perplexity_api(text_for_ai)
    except Exception as e:
        await send_response_url(response_url, {
            "response_type": "ephemeral",
            "text": f"오류가 발생했습니다: {e}",
        })
        return

    # 응답 파싱
    result = process_perplexity_response(api_result, text_for_ai)
    if not result["b_success"] or result["s_error"]:
        await send_response_url(response_url, {
            "response_type": "ephemeral",
            "text": result["s_error"] or "예약 정보를 파싱할 수 없습니다.",
        })
        return

    parsed = result["a_parsed"]

    # 알림 내용 보정
    alarm_content = normalize_alarm_content(parsed.get("content", ""))
    parsed["content"] = alarm_content

    # 주소 (청약 관련일 때만)
    alarm_address = ""
    if extracted_address and "청약" in alarm_content:
        alarm_address = extracted_address

    # 날짜/시간 조합
    alarm_datetime = build_alarm_datetime(parsed.get("date", ""), parsed.get("time", ""))

    # DB 저장
    insert_result = await _insert_alarm_record(alarm_content, alarm_address, alarm_datetime)
    if not insert_result["b_success"]:
        await send_response_url(response_url, {
            "response_type": "ephemeral",
            "text": insert_result["s_error"],
        })
        return

    # 성공 메시지
    message = _build_success_message(parsed, user_name, insert_result["i_cron_num"])
    await send_response_url(response_url, {
        "response_type": "in_channel",
        "text": message,
        "code": "0000",
    })


# ─── 엔드포인트 ────────────────────────────────────

@router.post("/mattermost_reserve")
async def mattermost_reserve(
    background_tasks: BackgroundTasks,
    text: str = Form(""),
    response_url: str = Form(""),
    user_name: str = Form("사용자"),
    channel_id: str = Form(""),
):
    """Mattermost 슬래시 명령어 /예약 처리"""
    # 입력 없으면 안내
    if not text.strip():
        return {
            "response_type": "ephemeral",
            "text": "사용법: `/예약 [날짜/시간] [내용]`\n예: `/예약 내일 오후 3시에 회의 알림`",
        }

    # 예약 완료 요청 확인
    complete_req = _parse_complete_request(text)
    if complete_req["b_complete"]:
        result = await _complete_alarm(complete_req["i_cron_num"])
        if not result["b_success"]:
            return {"response_type": "ephemeral", "text": result["s_error"]}
        return {
            "response_type": "ephemeral",
            "text": f"예약번호 {complete_req['i_cron_num']}번 예약을 완료 처리했습니다.",
            "code": "0000",
        }

    # 백그라운드로 예약 처리 위임
    background_tasks.add_task(_process_reservation, text.strip(), response_url, user_name)

    return {
        "response_type": "ephemeral",
        "text": "요청하신 내용을 AI에게 분석을 맡겼습니다...",
    }


@router.get("/reserve_list")
async def reserve_list():
    """예약 목록 조회 (complete_flag = N)"""
    try:
        async with get_mysql_session() as session:
            result = await session.execute(
                select(G5Alarm).where(G5Alarm.complete_flag == "N").order_by(G5Alarm.alarm_date.asc())
            )
            alarms = result.scalars().all()

            a_list = []
            for alarm in alarms:
                a_list.append({
                    "cron_num": alarm.cron_num,
                    "content": alarm.content,
                    "address": alarm.address,
                    "alarm_date": alarm.alarm_date.strftime("%Y-%m-%d %H:%M:%S") if alarm.alarm_date else "",
                    "complete_flag": alarm.complete_flag,
                    "channelid": alarm.channelid,
                    "webhook_url": alarm.webhook_url,
                })

            return {"b_success": True, "a_list": a_list}
    except Exception as e:
        return {"b_success": False, "s_error": str(e)}
