import re
from datetime import datetime, timedelta

from zoneinfo import ZoneInfo

KST = ZoneInfo("Asia/Seoul")

KOREAN_WEEKDAYS = ["일요일", "월요일", "화요일", "수요일", "목요일", "금요일", "토요일"]

WEEKDAY_MAP = {"일": 0, "월": 1, "화": 2, "수": 3, "목": 4, "금": 5, "토": 6}


def get_korean_weekday(weekday_num: int) -> str:
    """숫자 요일(0=일)을 한글 요일로 변환"""
    if 0 <= weekday_num <= 6:
        return KOREAN_WEEKDAYS[weekday_num]
    return ""


def format_datetime(date_str: str, time_str: str) -> str:
    """날짜/시간을 'N월 N일 N시 N분' 형식으로 포맷"""
    result = ""
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", date_str)
    if m:
        result += f"{int(m.group(2))}월 {int(m.group(3))}일 "

    m = re.match(r"(\d{1,2}):(\d{2})", time_str)
    if m:
        hour = int(m.group(1))
        minute = int(m.group(2))
        result += f"{hour}시"
        if minute > 0:
            result += f" {minute}분"

    return result.strip()


def build_alarm_datetime(date_str: str, time_str: str) -> str:
    """DB 저장용 날짜/시간 조합"""
    dt = f"{date_str} {time_str}".strip()
    if re.match(r"^\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}$", dt):
        dt += ":00"
    return dt


def format_alarm_date(alarm_date: str) -> str:
    """알림 날짜를 'YYYY년 MM월 DD일 HH시 MM분' 형식으로 포맷 (alarm_sender용)"""
    alarm_date = alarm_date.strip()
    if not alarm_date:
        return ""
    m = re.match(r"^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})", alarm_date)
    if m:
        return f"{m.group(1)}년 {m.group(2)}월 {m.group(3)}일 {m.group(4)}시 {m.group(5)}분"
    return alarm_date


def apply_minute_only_expression(text: str, parsed: dict) -> dict:
    """'NN분에' 표현만 있고 시 표현이 없을 때 현재 시를 기준으로 처리"""
    text = text.strip()

    # 'NN분에' 패턴
    m = re.search(r"(\d{1,2})\s*분에", text)
    if not m:
        return parsed

    # 'NN분 뒤/후' 제외
    if re.search(r"\d{1,2}\s*분\s*(뒤|후)", text):
        return parsed

    # 시각 표기('NN시' 또는 'HH:MM')가 있으면 제외
    if re.search(r"\d{1,2}\s*시", text) or re.search(r"\d{1,2}:\d{2}", text):
        return parsed

    # 오전/오후 표기가 있으면 제외
    if re.search(r"오전|오후|아침|저녁|밤|새벽", text):
        return parsed

    minute = int(m.group(1))
    if minute < 0 or minute > 59:
        return parsed

    now = datetime.now(KST)
    target = now.replace(second=0, microsecond=0)

    if minute < now.minute:
        target += timedelta(hours=1)

    target = target.replace(minute=minute)
    parsed["date"] = target.strftime("%Y-%m-%d")
    parsed["time"] = target.strftime("%H:%M")
    return parsed


def has_explicit_date_expression(text: str) -> bool:
    """사용자 요청에 명시적 날짜 표현이 포함되었는지 확인"""
    text = text.strip()
    if not text:
        return False
    pattern = r"(\d{4})[.\-/](\d{1,2})[.\-/](\d{1,2})|(\d{1,2})\s*월\s*(\d{1,2})\s*일|오늘|내일|모레|금일|익일|(다음주|이번주|이번)?\s*(일|월|화|수|목|금|토)요일"
    return bool(re.search(pattern, text))


def correct_past_datetime(parsed: dict) -> dict:
    """과거 날짜/시간이면 미래가 될 때까지 +1일 반복 (최대 7일)"""
    if not parsed.get("date") or not parsed.get("time"):
        return parsed

    dt_str = build_alarm_datetime(parsed["date"], parsed["time"])
    now = datetime.now(KST)

    try:
        target = datetime.strptime(dt_str, "%Y-%m-%d %H:%M:%S").replace(tzinfo=KST)
    except ValueError:
        return parsed

    count = 0
    while target < now and count < 7:
        target += timedelta(days=1)
        count += 1

    if count > 0:
        parsed["date"] = target.strftime("%Y-%m-%d")

    return parsed
