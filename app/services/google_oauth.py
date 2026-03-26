import json
import time
from pathlib import Path

import httpx

from app.config import settings


class GoogleOAuthTokenManager:
    """OAuth 토큰을 파일에 저장하고 자동 갱신"""

    def __init__(self, token_file: str = ""):
        self.token_file = Path(token_file or settings.GOOGLE_TOKEN_FILE)

    def save_token(self, token_data: dict) -> bool:
        if "expires_in" in token_data:
            token_data["expires_at"] = int(time.time()) + int(token_data["expires_in"])
        self.token_file.parent.mkdir(parents=True, exist_ok=True)
        self.token_file.write_text(
            json.dumps(token_data, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        return True

    def load_token(self) -> dict | None:
        if not self.token_file.exists():
            return None
        try:
            data = json.loads(self.token_file.read_text(encoding="utf-8"))
            return data if isinstance(data, dict) else None
        except Exception:
            return None

    def has_token(self) -> bool:
        return self.token_file.exists()

    def is_expired(self) -> bool:
        token = self.load_token()
        if not token or "expires_at" not in token:
            return True
        return (time.time() + 300) >= int(token["expires_at"])

    async def refresh_token(self) -> dict:
        token = self.load_token()
        if not token or "refresh_token" not in token:
            return {"b_success": False, "a_token": None, "s_error": "Refresh token이 없습니다."}

        post_data = {
            "client_id": settings.GOOGLE_CLIENT_ID,
            "client_secret": settings.GOOGLE_CLIENT_SECRET,
            "refresh_token": token["refresh_token"],
            "grant_type": "refresh_token",
        }

        async with httpx.AsyncClient(timeout=30) as client:
            resp = await client.post(
                settings.GOOGLE_TOKEN_URI,
                data=post_data,
                headers={"Content-Type": "application/x-www-form-urlencoded"},
            )

        if resp.status_code != 200:
            return {
                "b_success": False,
                "a_token": None,
                "s_error": f"토큰 갱신 요청 실패: HTTP {resp.status_code}",
            }

        new_token = resp.json()
        if not isinstance(new_token, dict) or "access_token" not in new_token:
            return {"b_success": False, "a_token": None, "s_error": "토큰 갱신 응답 파싱 실패"}

        if "refresh_token" not in new_token:
            new_token["refresh_token"] = token["refresh_token"]

        self.save_token(new_token)
        return {"b_success": True, "a_token": new_token, "s_error": ""}

    async def get_valid_access_token(self) -> dict:
        if not self.has_token():
            return {
                "b_success": False,
                "s_access_token": "",
                "s_error": "토큰이 없습니다. OAuth 인증이 필요합니다.",
            }

        if not self.is_expired():
            token = self.load_token()
            return {"b_success": True, "s_access_token": token["access_token"], "s_error": ""}

        result = await self.refresh_token()
        if not result["b_success"]:
            return {"b_success": False, "s_access_token": "", "s_error": result["s_error"]}

        return {"b_success": True, "s_access_token": result["a_token"]["access_token"], "s_error": ""}

    def delete_token(self) -> bool:
        if self.token_file.exists():
            self.token_file.unlink()
        return True
