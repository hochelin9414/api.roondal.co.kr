import json
import logging
import re
from datetime import datetime, timedelta
from pathlib import Path

import httpx
from zoneinfo import ZoneInfo

from app.config import settings
from app.utils.datetime_helpers import (
    KST,
    WEEKDAY_MAP,
    apply_minute_only_expression,
    correct_past_datetime,
    get_korean_weekday,
    has_explicit_date_expression,
)
from app.utils.json_extractor import extract_json_from_content

logger = logging.getLogger(__name__)


def _build_system_prompt() -> str:
    """예약용 Perplexity 시스템 프롬프트 생성"""
    now = datetime.now(KST)
    current_datetime = now.strftime("%Y-%m-%d %H:%M")
    # Python weekday: Mon=0 → 변환: 일(0),월(1)...토(6)
    py_weekday = now.weekday()  # Mon=0
    kr_weekday_idx = (py_weekday + 1) % 7  # Sun=0
    current_weekday = get_korean_weekday(kr_weekday_idx)
    current_date_info = now.strftime("%Y년 %m월 %d일") + f" {current_weekday}"

    return "\n".join([
        "너는 사용자의 한국어 요청에서 날짜와 시간을 추출하는 파서다.",
        f"현재 시간: {current_datetime} (한국 시간, Asia/Seoul)",
        f"현재 날짜: {current_date_info}",
        "",
        "반드시 아래 JSON 형식으로만 응답해. 다른 텍스트 없이 JSON만 출력해:",
        "{",
        '  "success": true 또는 false,',
        '  "date": "YYYY-MM-DD" (추출된 날짜, 없으면 빈 문자열),',
        '  "time": "HH:MM" (24시간 형식, 없으면 빈 문자열),',
        '  "content": "예약 내용 요약",',
        '  "address": "주소 (없으면 빈 문자열)",',
        '  "error": "에러 메시지 (success가 false일 때만)"',
        "}",
        "",
        "규칙:",
        "1. '오늘', '내일', '모레' 등은 현재 날짜 기준으로 계산",
        "2. '오후 3시' → '15:00', '아침 9시' → '09:00'",
        "3. 요청 시간이 현재보다 과거면 success: false, error: '이미 지난 시간입니다.'",
        "4. 날짜가 없으면 date는 빈 문자열로 반환",
        "5. content는 간결한 명사구로 작성한다",
        "   - 좋은 예: '업무 시작', '약 복용', '청약', '회의 준비', '운동'",
        "   - 나쁜 예: '업무를 시작해야 합니다', '약을 먹어야 합니다'",
        "6. content에는 날짜/시간 표현을 넣지 않는다",
        "7. 시간이 없거나 모호하면 success: false, error: '날짜/시간을 더 구체적으로 입력해 주세요.'",
        "8. '이따가/이따' 같은 추상 시간은 오늘 기준으로 해석한다",
        "9. 'NN분에'만 있고 시 표현이 전혀 없는 경우:",
        "   - 현재 시간을 기준으로 해석",
        "   - 예1: 현재 14:45, '50분에 알림' → time: '14:50'",
        "   - 예2: 현재 14:45, '40분에 알림' → time: '15:40'",
        "   - 'NN분 뒤/후'는 이 규칙에서 제외",
        "10. 'NN분 뒤/후'는 현재 시간에서 NN분을 더한 절대 시각으로 변환",
        "11. '주소는 ...' 또는 '주소: ...'가 있으면 address에 넣고, 없으면 빈 문자열",
        "12. '2시'처럼 오전/오후가 없으면 가까운 미래로 해석한다",
        "13. 요일 기반 날짜 계산 (주는 월요일 시작 기준):",
        "   - 'X요일' 또는 '이번주 X요일': 오늘 이후 가장 가까운 해당 요일 (오늘 포함, 지났으면 다음 주)",
        "   - '다음주 X요일': 이번 주 월요일에 7일을 더한 주의 해당 요일",
        f"   - 이번 주 월요일: {(now - timedelta(days=now.weekday())).strftime('%Y-%m-%d')}",
        f"   - 다음 주 월요일: {(now - timedelta(days=now.weekday()) + timedelta(days=7)).strftime('%Y-%m-%d')}",
        f"   - 예시: '다음주 월요일' = {(now - timedelta(days=now.weekday()) + timedelta(days=7)).strftime('%Y-%m-%d')}, '다음주 금요일' = {(now - timedelta(days=now.weekday()) + timedelta(days=11)).strftime('%Y-%m-%d')}",
    ])


async def call_perplexity_api(user_input: str) -> dict:
    """Perplexity API 호출"""
    system_prompt = _build_system_prompt()

    payload = {
        "model": settings.PERPLEXITY_MODEL,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_input},
        ],
    }

    async with httpx.AsyncClient(timeout=30) as client:
        resp = await client.post(
            "https://api.perplexity.ai/chat/completions",
            json=payload,
            headers={
                "Authorization": f"Bearer {settings.PERPLEXITY_API_KEY}",
                "Content-Type": "application/json",
            },
        )

    return {
        "status_code": resp.status_code,
        "body": resp.json() if resp.status_code == 200 else {},
        "raw": resp.text,
    }


def _parse_fallback_by_text(text: str) -> dict | None:
    """AI JSON 파싱 실패 시 사용자 원문 기반 로컬 보정 파서"""
    text = text.strip()
    if not text:
        return None

    now = datetime.now(KST)
    date_str = ""
    time_str = ""
    content = text

    # YYYY-MM-DD
    m = re.search(r"(\d{4})[.\-/](\d{1,2})[.\-/](\d{1,2})", text)
    if m:
        y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
        try:
            datetime(y, mo, d)
            date_str = f"{y:04d}-{mo:02d}-{d:02d}"
        except ValueError:
            pass

    # N월 N일
    if not date_str:
        m = re.search(r"(\d{1,2})\s*월\s*(\d{1,2})\s*일", text)
        if m:
            y = now.year
            mo, d = int(m.group(1)), int(m.group(2))
            try:
                candidate = datetime(y, mo, d, tzinfo=KST)
                today = now.replace(hour=0, minute=0, second=0, microsecond=0)
                if candidate < today:
                    y += 1
                    datetime(y, mo, d)  # validate
                date_str = f"{y:04d}-{mo:02d}-{d:02d}"
            except ValueError:
                pass

    # 상대 날짜
    if not date_str:
        if re.search(r"오늘|금일", text):
            date_str = now.strftime("%Y-%m-%d")
        elif re.search(r"내일|익일", text):
            date_str = (now + timedelta(days=1)).strftime("%Y-%m-%d")
        elif re.search(r"모레", text):
            date_str = (now + timedelta(days=2)).strftime("%Y-%m-%d")

    # 요일 기반
    if not date_str:
        m = re.search(r"(다음주|이번주|이번)?\s*(일|월|화|수|목|금|토)요일", text)
        if m:
            prefix = m.group(1) or ""
            weekday_name = m.group(2)
            if weekday_name in WEEKDAY_MAP:
                target_wd = WEEKDAY_MAP[weekday_name]
                if prefix == "다음주":
                    # 다음주 = 이번 주 월요일 기준 +7일한 주
                    # 오늘로부터 다음 주 월요일까지의 일수: 7 - now.weekday() (1~7)
                    python_target_wd = (target_wd - 1) % 7  # Sun=0 → Mon=0 변환
                    days_to_next_monday = 7 - now.weekday()
                    days_ahead = days_to_next_monday + python_target_wd
                else:
                    current_wd = (now.weekday() + 1) % 7  # Python Mon=0 → Sun=0
                    days_ahead = (target_wd - current_wd + 7) % 7
                target = now + timedelta(days=days_ahead)
                date_str = target.strftime("%Y-%m-%d")

    # 오전/오후 + N시 N분
    m = re.search(r"(오전|오후|아침|저녁|밤|새벽)\s*(\d{1,2})\s*시(?:\s*(\d{1,2})\s*분)?", text)
    if m:
        ampm = m.group(1)
        hour = int(m.group(2))
        minute = int(m.group(3)) if m.group(3) else 0
        if 1 <= hour <= 12 and 0 <= minute <= 59:
            if ampm in ("오후", "저녁", "밤"):
                if hour < 12:
                    hour += 12
            else:
                if hour == 12:
                    hour = 0
            time_str = f"{hour:02d}:{minute:02d}"

    # HH:MM
    if not time_str:
        m = re.search(r"\b(\d{1,2}):(\d{2})\b", text)
        if m:
            h, mi = int(m.group(1)), int(m.group(2))
            if 0 <= h <= 23 and 0 <= mi <= 59:
                time_str = f"{h:02d}:{mi:02d}"

    # N시 N분 (오전/오후 미지정)
    if not time_str:
        m = re.search(r"(\d{1,2})\s*시(?:\s*(\d{1,2})\s*분)?", text)
        if m:
            hour = int(m.group(1))
            minute = int(m.group(2)) if m.group(2) else 0
            if 1 <= hour <= 12 and 0 <= minute <= 59:
                target = now.replace(hour=hour, minute=minute, second=0, microsecond=0)
                if target < now:
                    target = target.replace(hour=hour + 12)
                    if target < now:
                        target += timedelta(days=1)
                time_str = target.strftime("%H:%M")
                if not date_str:
                    date_str = target.strftime("%Y-%m-%d")

    # 날짜/시간 키워드 제거 후 내용 정리
    content = re.sub(r"(\d{4})[.\-/](\d{1,2})[.\-/](\d{1,2})", "", content)
    content = re.sub(r"(\d{1,2})\s*월\s*(\d{1,2})\s*일", "", content)
    content = re.sub(r"(오늘|내일|모레|금일|익일|다음주|이번주|이번)\s*", "", content)
    content = re.sub(r"(일|월|화|수|목|금|토)요일", "", content)
    content = re.sub(r"(오전|오후|아침|저녁|밤|새벽)\s*(\d{1,2})\s*시(?:\s*(\d{1,2})\s*분)?", "", content)
    content = re.sub(r"\b(\d{1,2}):(\d{2})\b", "", content)
    content = re.sub(r"(\d{1,2})\s*시(?:\s*(\d{1,2})\s*분)?", "", content)
    content = re.sub(r"\s+", " ", content).strip()
    if not content:
        content = "알림"

    if not date_str and not time_str:
        return None

    return {
        "success": True,
        "date": date_str,
        "time": time_str,
        "content": content,
        "address": "",
        "error": "",
    }


def process_perplexity_response(api_result: dict, user_input: str) -> dict:
    """Perplexity 응답 처리 - JSON 파싱 및 유효성 검사"""
    result = {
        "b_success": False,
        "s_error": "",
        "a_parsed": {},
    }

    if api_result["status_code"] < 200 or api_result["status_code"] >= 300:
        result["s_error"] = "API 호출에 실패했습니다."
        return result

    content = ""
    body = api_result.get("body", {})
    if isinstance(body, dict):
        choices = body.get("choices", [])
        if choices:
            content = choices[0].get("message", {}).get("content", "")

    json_str = extract_json_from_content(content)
    try:
        parsed = json.loads(json_str)
    except (json.JSONDecodeError, TypeError):
        parsed = None

    if not isinstance(parsed, dict):
        # 폴백 파싱
        fallback = _parse_fallback_by_text(user_input)
        if fallback:
            parsed = fallback
        else:
            _log_perplexity_response(content, json_str)
            result["s_error"] = "AI 응답을 파싱할 수 없습니다."
            return result

    result["a_parsed"] = parsed

    # 분 단위 표현 보정
    parsed = apply_minute_only_expression(user_input, parsed)
    result["a_parsed"] = parsed

    has_original_date = bool(parsed.get("date"))

    # 날짜가 없으면 오늘
    if not parsed.get("date"):
        parsed["date"] = datetime.now(KST).strftime("%Y-%m-%d")
        result["a_parsed"] = parsed

    # 과거 시간 보정
    parsed = correct_past_datetime(parsed)
    result["a_parsed"] = parsed

    # success: false 체크
    if parsed.get("success") is False:
        result["s_error"] = parsed.get("error", "예약 정보를 확인할 수 없습니다.")
        return result

    # 시간 필수 검증
    if not parsed.get("time"):
        if has_explicit_date_expression(user_input) and has_original_date:
            parsed["time"] = "09:00"
            result["a_parsed"] = parsed
        else:
            result["s_error"] = "날짜/시간을 더 구체적으로 입력해 주세요."
            return result

    result["b_success"] = True
    return result


def _log_perplexity_response(content: str, extracted: str) -> None:
    """Perplexity 응답 디버깅 로그"""
    log_dir = Path(settings.LOG_DIR)
    log_dir.mkdir(parents=True, exist_ok=True)
    log_file = log_dir / "perplexity_debug.log"
    now_str = datetime.now(KST).strftime("%Y-%m-%d %H:%M:%S")
    entry = f"[{now_str}] === Perplexity 파싱 실패 ===\n원본 응답:\n{content}\n\n추출 시도:\n{extracted}\n\n"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(entry)
