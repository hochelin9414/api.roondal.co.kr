# Google Calendar 연동

## 목적

알림 예약 시 Google Calendar에 일정을 자동으로 등록한다.
별도의 수동 입력 없이 Mattermost에서 예약한 알림이 캘린더에도 동기화된다.

## 동작 방식

알림 예약 흐름(`/reserve/mattermost_reserve`) 내에서 DB 저장 후 자동 호출된다.
별도의 API 엔드포인트는 없으며, 예약 기능의 일부로 동작한다.

## 처리 흐름

```
알림 예약 완료 (DB 저장 후)
    |
    v
토큰 파일 존재 여부 확인
(./tmp/google_oauth_token.json)
    |
    +-- 없으면: Calendar 등록 건너뜀 (로그 기록)
    |
    v
토큰 만료 여부 확인
    |
    +-- 만료 시: 자동 갱신 (refresh_token 사용)
    |
    v
Google Calendar API 호출 (비동기)
- 제목: 알림 내용 (content)
- 시작: alarm_date
- 종료: alarm_date + 30분
- 설명: 주소 (address, 있을 때만)
- 알림: 10분 전 팝업
    |
    v
일정 등록 완료
실패 시 로그 기록 후 예약은 정상 처리
```

## 토큰 관리

- 토큰 저장 경로: `./tmp/google_oauth_token.json`
- 만료 기준: 현재 시각 기준 5분 여유를 두고 판단
- 갱신 방식: `refresh_token`으로 자동 갱신 (만료 시마다)
- 토큰이 없으면 Calendar 등록 없이 예약만 진행

## 로그

- 작업 로그: `logs/calendar_debug.log`
- 에러 로그: `logs/calendar_error.log`

## 관련 파일

| 파일 | 역할 |
|------|------|
| `app/services/google_calendar.py` | Calendar API 호출 및 이벤트 생성 |
| `app/services/google_oauth.py` | 토큰 로드/저장/갱신 관리 |
| `app/routers/oauth.py` | 최초 토큰 발급을 위한 OAuth 흐름 |

## 사전 조건

Google Calendar를 사용하려면 최초 1회 OAuth 인증이 필요하다.
`/oauth/authorize` 엔드포인트로 Google 로그인을 완료해야 토큰이 발급된다.
→ [Google OAuth 인증](05_google_oauth.md) 참고
