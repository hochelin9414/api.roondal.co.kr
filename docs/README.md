# Roondal API

Mattermost 통합 알림 API 서버.
Mattermost 슬래시 명령어로 알림을 예약하고, 정시에 자동 발송하는 시스템.

- **버전**: 2.0.0
- **기술 스택**: FastAPI, SQLAlchemy, PostgreSQL, MySQL, Google Calendar API, Perplexity AI
- **서버**: Nginx (리버스 프록시) + Uvicorn (FastAPI 앱서버)

## 시스템 구성

```
Mattermost
    |
    | 슬래시 명령어 / 웹훅
    v
Nginx (443 HTTPS)
    |
    v
FastAPI (Uvicorn :8000)
    |
    +-- MySQL (g5_alarm 테이블 - 알림 예약 데이터)
    +-- PostgreSQL (Mattermost DB - 채널 정보)
    +-- Google Calendar API
    +-- Perplexity AI API
```

## API 엔드포인트 목록

| 메서드 | 경로 | 설명 |
|--------|------|------|
| GET | / | API 소개 및 엔드포인트 목록 |
| GET | /get_ip | 클라이언트 IP 조회 |
| POST | /reserve/mattermost_reserve | 알림 예약 |
| GET | /reserve/reserve_list | 미완료 알림 목록 조회 |
| POST | /clear | 채팅창 클리어 (50줄) |
| POST | /clear/quiet | 채팅창 클리어 (자신에게만) |
| POST | /clear/simple | 채팅창 클리어 (20줄) |
| POST | /clear/max | 채팅창 클리어 (100줄) |
| GET | /oauth/authorize | Google OAuth 인증 시작 |
| GET | /oauth/callback | Google OAuth 콜백 처리 |

## 기능 문서

- [알림 예약](features/01_alarm_reserve.md) - Mattermost 슬래시 명령어로 알림 예약
- [알림 발송](features/02_alarm_sender.md) - 크론잡 기반 정시 알림 발송
- [채널 클리어](features/03_channel_clear.md) - Mattermost 채팅창 정리
- [Google Calendar 연동](features/04_google_calendar.md) - 예약 시 캘린더 일정 자동 등록
- [Google OAuth 인증](features/05_google_oauth.md) - Calendar API 사용을 위한 OAuth 토큰 관리

## 환경 설정

`.env.example` 참고.
주요 설정 항목: MySQL 접속 정보, PostgreSQL 접속 정보, Perplexity API 키, Google OAuth 설정, Mattermost 웹훅 설정.

## 실행

```bash
# 서비스 시작
systemctl start roondal-api

# 로그 확인
journalctl -u roondal-api -f

# 크론잡 확인 (알림 발송)
crontab -l
```
