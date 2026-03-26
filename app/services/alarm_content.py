import re


def normalize_alarm_content(content: str) -> str:
    """알림 내용 문장 보정 - 간결한 명사구를 자연스러운 알림 문구로 변환"""
    content = content.strip()
    if not content:
        return "알림 시간입니다."

    # 선행 날짜/시간 표현 제거
    content = re.sub(r"^(오늘|내일|모레|금일|익일|이따가|잠시후|나중에)\s*", "", content)
    content = re.sub(r"^(오전|오후|아침|저녁|밤|새벽)\s*", "", content)
    content = re.sub(r"^\d{1,2}시(\s*\d{1,2}분)?(에)?\s*", "", content)
    content = re.sub(r"^\d{1,2}:\d{2}(에)?\s*", "", content)
    content = re.sub(r"^\d{1,2}분\s*(뒤에|후에|뒤|후)\s*", "", content)
    content = content.strip()

    # 시스템형 표현 제거
    content = re.sub(r"보내드(리겠습니다|릴게요|립니다)", "", content)
    content = re.sub(r"알려드(리겠습니다|릴게요|립니다)", "", content)

    # 요청형 표현 제거
    content = re.sub(
        r"(알려주세요|알려줘요|보내주세요|보내줘요|해\s*주세요|해주세요|해줘요|하세요|해요|주세요)$",
        "",
        content,
    )
    content = content.strip()
    if not content:
        return "알림 시간입니다."

    # 불필요한 조사 제거
    content = re.sub(r"(을|를|에|이|가)\s*$", "", content)
    content = content.strip()

    # 알림이 울립니다 정리
    content = re.sub(r"(알림|알람)이?\s*울립니다$", r"\1", content)
    content = content.strip()

    # 이미 완성된 문장이면 그대로
    if re.search(r"입니다\.?$", content) or re.search(r"시간입니다\.?$", content):
        if not content.endswith("."):
            content += "."
        return content

    # 문장 종결 부호 제거
    content = re.sub(r"[.!?]$", "", content)
    content = content.strip()

    # "~하기" → "~할 시간입니다."
    m = re.match(r"(.+)하기$", content)
    if m:
        return f"{m.group(1)}할 시간입니다."

    # "~해야 해/함" 제거
    content = re.sub(r"(해야\s*(해|함|한다|하다))$", "", content)
    content = content.strip()

    return f"{content} 시간입니다."
