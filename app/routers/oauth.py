from urllib.parse import urlencode

import httpx
from fastapi import APIRouter, Query
from fastapi.responses import HTMLResponse, RedirectResponse

from app.config import settings
from app.services.google_oauth import GoogleOAuthTokenManager

router = APIRouter(prefix="/oauth", tags=["oauth"])


@router.get("/authorize")
async def authorize():
    """Google OAuth 인증 시작 - Google 로그인 페이지로 리다이렉트"""
    params = {
        "client_id": settings.GOOGLE_CLIENT_ID,
        "redirect_uri": settings.GOOGLE_REDIRECT_URI,
        "response_type": "code",
        "scope": "https://www.googleapis.com/auth/calendar",
        "access_type": "offline",
        "prompt": "consent",
    }
    auth_url = f"{settings.GOOGLE_AUTH_URI}?{urlencode(params)}"
    return RedirectResponse(url=auth_url)


@router.get("/callback")
async def callback(code: str = Query(""), error: str = Query("")):
    """Google OAuth 콜백 - authorization code를 access_token으로 교환"""
    if error:
        return HTMLResponse(f"OAuth 인증 실패: {error}", status_code=400)

    if not code:
        return HTMLResponse("Authorization code가 없습니다.", status_code=400)

    post_data = {
        "code": code,
        "client_id": settings.GOOGLE_CLIENT_ID,
        "client_secret": settings.GOOGLE_CLIENT_SECRET,
        "redirect_uri": settings.GOOGLE_REDIRECT_URI,
        "grant_type": "authorization_code",
    }

    async with httpx.AsyncClient(timeout=30) as client:
        resp = await client.post(
            settings.GOOGLE_TOKEN_URI,
            data=post_data,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
        )

    if resp.status_code != 200:
        return HTMLResponse(
            f"토큰 교환 실패: HTTP {resp.status_code}<br><pre>{resp.text}</pre>",
            status_code=500,
        )

    token_data = resp.json()
    if not isinstance(token_data, dict) or "access_token" not in token_data:
        return HTMLResponse("토큰 응답 파싱 실패", status_code=500)

    token_manager = GoogleOAuthTokenManager()
    token_manager.save_token(token_data)

    return HTMLResponse("""<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>OAuth 인증 완료</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        h1 { color: #4CAF50; margin-bottom: 20px; }
        p { color: #666; line-height: 1.6; }
        .success-icon { font-size: 60px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">\u2705</div>
        <h1>OAuth 인증 완료!</h1>
        <p>구글 캘린더 연동이 완료되었습니다.</p>
        <p>이제 알림 등록 시 자동으로 캘린더에 추가됩니다.</p>
    </div>
</body>
</html>""")
