import json
import re


def extract_json_from_content(content: str) -> str:
    """AI 응답에서 JSON 문자열 추출"""
    content = content.strip()

    # 1. ```json ... ``` 코드 블록
    m = re.search(r"```(?:json)?\s*([\s\S]*?)\s*```", content)
    if m:
        candidate = m.group(1).strip()
        if _is_valid_json(candidate):
            return candidate

    # 2. { ... } 직접 추출
    m = re.search(r"\{[\s\S]*\}", content)
    if m:
        candidate = m.group(0).strip()
        if _is_valid_json(candidate):
            return candidate

    return content


def _is_valid_json(s: str) -> bool:
    try:
        json.loads(s)
        return True
    except (json.JSONDecodeError, TypeError):
        return False
