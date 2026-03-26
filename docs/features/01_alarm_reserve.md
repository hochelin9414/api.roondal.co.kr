# 알림 예약

## 목적

Mattermost 슬래시 명령어로 원하는 시간에 알림을 예약한다.
사용자가 자연어로 날짜/시간을 입력하면 Perplexity AI가 파싱하여 DB에 저장하고, Google Calendar에도 자동으로 일정을 등록한다.

## 엔드포인트

### POST /reserve/mattermost_reserve

Mattermost 슬래시 명령어 요청을 처리하여 알림을 예약한다.

**요청 파라미터** (Form 데이터)

| 파라미터 | 타입 | 설명 |
|---------|------|------|
| text | string | 슬래시 명령어 본문 (예: "내일 오후 3시 회의") |
| response_url | string | Mattermost 후속 응답 URL (3시간 유효) |
| user_name | string | 요청한 사용자 이름 |
| channel_id | string | 요청한 채널 ID |

**응답** (즉시 반환 - Mattermost 응답 형식)

```json
{
  "response_type": "ephemeral",
  "text": "알림을 예약 중입니다..."
}
```

실제 처리 결과는 `response_url`로 후속 전송됨.

**예약 완료 처리**

`text`에 4자리 예약번호만 입력하면 해당 예약을 완료(complete_flag='Y') 처리한다.

```
/알림예약 1234
```

---

### GET /reserve/reserve_list

complete_flag='N'인 미완료 알림 목록을 조회한다.

**응답**

```json
{
  "b_success": true,
  "a_list": [
    {
      "cron_num": 1234,
      "content": "회의 시간입니다.",
      "address": "",
      "alarm_date": "2026-03-26 14:00:00",
      "complete_flag": "N",
      "channelid": "dcbaorxfjifiiq8nxfd9dbytmc",
      "webhook_url": "https://msg.roondal.co.kr/hooks/..."
    }
  ]
}
```

## 처리 흐름

```
사용자 입력 (text)
    |
    v
주소 추출 (정규식)
예: "서울시 강남구 테헤란로 123" 형태 감지
    |
    v
Perplexity AI 호출
자연어 날짜/시간/내용 파싱
예: "내일 오후 3시 회의" → {date: "...", time: "15:00", content: "회의"}
    |
    v (파싱 실패 시)
로컬 정규식 폴백 파싱
    |
    v
시간 유효성 검사
- 과거 시간이면 다음날로 자동 보정 (최대 7일)
- 그래도 과거이면 실패 처리
    |
    v
알림 내용 정규화
예: "회의" → "회의 시간입니다."
    |
    v
DB 저장 (MySQL g5_alarm 테이블)
- 중복 없는 4자리 예약번호 생성
- 최신 채널 ID 조회 (PostgreSQL)
- 채널 ID로 웹훅 URL 조회
    |
    v
Google Calendar 일정 등록
    |
    v
결과를 response_url로 Mattermost에 전송
```

## Perplexity AI 파싱 규칙

- `오늘`, `내일`, `모레` 처리
- `오후 3시`, `09:00`, `3시 30분` 형식 변환
- `이번주 월요일`, `다음주 금요일` 요일 기반 계산
- `분에` 표현만 있을 경우 현재 시간 기준 해석
- 명시적 날짜 없이 시간만 있으면 기본 시각 미적용

## 관련 파일

| 파일 | 역할 |
|------|------|
| `app/routers/reserve.py` | 엔드포인트 정의 및 예약 흐름 조율 |
| `app/services/perplexity.py` | Perplexity AI API 호출 및 응답 파싱 |
| `app/services/alarm_content.py` | 알림 내용 자연어 정규화 |
| `app/services/google_calendar.py` | Google Calendar 일정 등록 |
| `app/services/mattermost.py` | Mattermost 응답 전송 |
| `app/models/alarm.py` | g5_alarm 테이블 ORM 모델 |
| `app/utils/datetime_helpers.py` | 날짜/시간 파싱 헬퍼 |
| `app/utils/json_extractor.py` | AI 응답에서 JSON 추출 |

## 데이터베이스 (MySQL - g5_alarm)

| 컬럼 | 타입 | 설명 |
|------|------|------|
| cron_num | INT (PK) | 예약번호 (4자리) |
| content | VARCHAR(500) | 알림 내용 |
| address | VARCHAR(500) | 주소 (청약 알림 시 사용) |
| alarm_date | DATETIME | 알림 발송 시각 |
| complete_flag | VARCHAR(1) | 완료 여부 (N/Y) |
| channelid | VARCHAR(100) | Mattermost 채널 ID |
| webhook_url | VARCHAR(500) | Mattermost 웹훅 URL |
