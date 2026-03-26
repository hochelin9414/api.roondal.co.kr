# Google OAuth 인증

## 목적

Google Calendar API 사용을 위한 OAuth 2.0 인증 토큰을 발급하고 관리한다.
최초 1회 브라우저 로그인으로 발급된 토큰을 파일에 저장하고, 이후 자동 갱신하여 재로그인 없이 사용한다.

## 엔드포인트

### GET /oauth/authorize

Google 로그인 페이지로 리다이렉트한다.

**동작**: 브라우저에서 접근 시 Google OAuth 동의 화면으로 이동.
**필요 권한 스코프**: `https://www.googleapis.com/auth/calendar`

---

### GET /oauth/callback

Google OAuth 콜백을 처리한다. Google이 인증 후 자동으로 호출한다.

**쿼리 파라미터**

| 파라미터 | 설명 |
|---------|------|
| code | Google이 발급한 authorization code |
| state | CSRF 방지용 state 값 |

**처리 결과**: authorization code를 access_token으로 교환하여 파일에 저장.

## 토큰 파일

- **저장 경로**: `./tmp/google_oauth_token.json`
- **포함 정보**: access_token, refresh_token, expires_at

## 처리 흐름

```
브라우저에서 GET /oauth/authorize 접근
    |
    v
Google 로그인 및 권한 동의
    |
    v
Google → GET /oauth/callback?code=...
    |
    v
authorization code → access_token 교환
(Google Token URI 호출)
    |
    v
토큰을 ./tmp/google_oauth_token.json에 저장
    |
    v
이후 Calendar 연동 시 자동으로 토큰 사용
만료 시 refresh_token으로 자동 갱신
```

## 최초 설정 절차

1. `.env`에 Google OAuth 설정 입력
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI` (예: `https://api.roondal.co.kr/oauth/callback`)
2. 브라우저에서 `https://api.roondal.co.kr/oauth/authorize` 접근
3. Google 계정으로 로그인 및 Calendar 권한 동의
4. 콜백 처리 완료 후 토큰 파일 자동 생성

## 관련 파일

| 파일 | 역할 |
|------|------|
| `app/routers/oauth.py` | OAuth 엔드포인트 정의 |
| `app/services/google_oauth.py` | `GoogleOAuthTokenManager` - 토큰 저장/로드/갱신 |
| `app/config.py` | Google OAuth 환경변수 설정 |
